<?php
/**
 * Prüfungen der Golfreisen – gegen eine Wegwerf-Datenbank.
 *
 *   php tests/reisen.php
 *
 * Wie tests/baukasten.php: eigene config.php und SQLite-Datei in einem
 * temporären Ordner, ein eigener PHP-Entwicklungsserver. Die echte
 * Datenbank wird nicht angefasst.
 *
 * Geprüft wird vor allem, was man auf der Seite nicht sieht: dass der
 * Preis nur auf dem Server entsteht und kein Formularfeld ihn verbiegt,
 * dass Plätze Personen zählen, dass der Frühbucherrabatt am richtigen Tag
 * endet, dass eine unveröffentlichte Reise nur die eigenen Leute sehen und
 * dass keine Instanz Reisen, Buchungen oder Kunden einer anderen sieht.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
define('GP_ROOT', dirname(__DIR__));

$ordner = sys_get_temp_dir() . '/teepilot-reisen-' . bin2hex(random_bytes(4));
mkdir($ordner, 0700, true);
$port = 19000 + random_int(0, 999);
file_put_contents($ordner . '/config.php', '<?php return ' . var_export([
    'db' => ['driver' => 'sqlite', 'path' => $ordner . '/test.sqlite'],
    'secret' => bin2hex(random_bytes(32)),
    'base_url' => 'http://127.0.0.1:' . $port,
    'demo_zugang' => false,
    'mail' => ['from_name' => 'TeePilot Test', 'from_email' => 'test@example.org', 'transport' => 'keiner'],
    'debug' => false,
], true) . ';');
putenv('GP_CONFIG=' . $ordner . '/config.php');
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
require GP_ROOT . '/lib/bootstrap.php';

$ergebnisse = ['ok' => 0, 'fehl' => 0];
function pruefe(bool $bedingung, string $text): void
{
    global $ergebnisse;
    $ergebnisse[$bedingung ? 'ok' : 'fehl']++;
    fwrite(STDOUT, ($bedingung ? "  \033[32m✓\033[0m " : "  \033[31m✗ FEHLER:\033[0m ") . $text . PHP_EOL);
    /* REISEN_ZEIGEN=1 zeigt bei einem Fehler die letzte Antwort des Servers. */
    if (!$bedingung && getenv('REISEN_ZEIGEN') && class_exists('Besucher', false) && Besucher::$letzte !== '') {
        $roh = preg_replace('#<(script|style)[^>]*>.*?</\1>#s', '', Besucher::$letzte) ?? '';
        fwrite(STDOUT, '      ' . mb_substr(trim(preg_replace('/\s+/', ' ', strip_tags($roh)) ?? ''), 0, 700) . PHP_EOL);
    }
}
function abschnitt(string $titel): void
{
    fwrite(STDOUT, PHP_EOL . $titel . PHP_EOL);
}

/* ------------------------------------------------------------ Bestand --- */

$start = 910000 + random_int(0, 80000);
DB::insert('workspaces', ['id' => $start, 'slug' => 'platzhalter', 'name' => 'x', 'erstellt' => date('Y-m-d H:i:s')]);
DB::delete('workspaces', 'id = :i', ['i' => $start]);

$a = Instanzen::anlegen(['name' => 'Golfschule Alpha', 'inhaber' => 'Anna Alpha', 'email' => 'anna@alpha.example',
    'paket' => 'pro', 'laufzeit' => 'monat', 'slug' => 'reise-alpha']);
$b = Instanzen::anlegen(['name' => 'Golfschule Beta', 'inhaber' => 'Bert Beta', 'email' => 'bert@beta.example',
    'paket' => 'pro', 'laufzeit' => 'monat', 'slug' => 'reise-beta']);
$c = Instanzen::anlegen(['name' => 'Golfschule Klein', 'inhaber' => 'Karl Klein', 'email' => 'karl@klein.example',
    'paket' => 'starter', 'laufzeit' => 'monat', 'slug' => 'reise-klein']);
$A = (int) $a['id'];
$B = (int) $b['id'];
$C = (int) $c['id'];
$pw = 'Test!2026sicher';
DB::update('users', ['passwort' => password_hash($pw, PASSWORD_DEFAULT)],
    "email IN ('anna@alpha.example', 'bert@beta.example', 'karl@klein.example')");

/* Im Paket „pro" ist das Modul erlaubt, aber nicht von selbst an – der Pro
   schaltet es ein. Bei der Starter-Instanz steht es ebenfalls in der
   Liste: Dort muss das Paket es trotzdem sperren. */
foreach ([$A, $B, $C] as $ws) {
    Tenant::setzen($ws);
    Tenant::einstellungSetzen('module', array_values(array_unique(array_merge(Module::standardFuerPlan('pro'), ['travel']))));
}
Tenant::setzen(0);

$tag = static fn (string $relativ): string => date('Y-m-d 00:00:00', strtotime($relativ));

/** Eine Reise mit allem, was die Rechnung braucht. */
$reiseAnlegen = static function (int $ws, array $daten) use ($tag): array {
    Tenant::setzen($ws);
    $id = Trips::speichern($daten + [
        'titel' => 'Testreise', 'ziel' => 'Son Servera', 'land' => 'Spanien',
        'start' => $tag('+90 days'), 'ende' => $tag('+97 days'),
        'plaetze' => 10, 'preis_cent' => 190000, 'preis_nichtgolfer_cent' => 140000,
        'ez_zuschlag_cent' => 30000, 'anzahlung_cent' => 50000, 'restzahlung_tage' => 30,
        'fruehbucher_bis' => $tag('+10 days'), 'fruehbucher_rabatt_cent' => 10000,
        'extras' => Util::json([
            ['name' => 'Leihschläger', 'preis_cent' => 9000, 'je' => 'person'],
            ['name' => 'Transfer', 'preis_cent' => 12000, 'je' => 'buchung'],
        ]),
        'warteliste' => 1, 'status' => 'veroeffentlicht', 'anreise' => 'flug',
    ]);
    $r = Tenant::find('trips', $id);
    Tenant::setzen(0);
    return $r;
};

try {
    /* ============================================== Die Preisrechnung === */

    abschnitt('Preisrechnung');
    $r = $reiseAnlegen($A, ['titel' => 'Rechenreise']);
    $jetzt = time();

    $x = Trips::berechnen($r, ['reisende' => [['name' => 'Eins']], 'zimmer' => 'dz'], $jetzt);
    pruefe($x['summe'] === 180000 && $x['personen'] === 1 && $x['rabatt'] === 10000,
        'eine Person im Doppelzimmer: Reisepreis minus Frühbucherrabatt');

    $x = Trips::berechnen($r, [
        'reisende' => [['name' => 'Eins'], ['name' => 'Zwei'], ['name' => 'Drei', 'golfer' => false]],
        'zimmer'   => 'ez',
        'extras'   => [0 => 5, 1 => 4, 9 => 3, -1 => 2],
    ], $jetzt);
    /* 2 × 1.900 + 1 × 1.400 + 3 × 300 EZ + 3 × 90 (5 auf 3 gekappt) + 1 × 120 (4 auf 1 gekappt) − 3 × 100 */
    pruefe($x['summe'] === 380000 + 140000 + 90000 + 27000 + 12000 - 30000,
        'Golfer, Nichtgolfer, Einzelzimmer, Zusatzleistungen und Rabatt ergeben die erwartete Summe');
    pruefe(array_column($x['extras'], 'anzahl') === [3, 1], 'je Person höchstens so oft wie Personen, je Buchung höchstens einmal');
    pruefe(count($x['extras']) === 2, 'Zusatzleistungen, die es an der Reise nicht gibt, fallen weg');
    pruefe($x['anzahlung'] === 150000 && $x['rest'] === $x['summe'] - 150000, 'Anzahlung je Person, Rest ist die Differenz');

    $bis = strtotime(substr((string) $r['fruehbucher_bis'], 0, 10) . ' 23:59:59');
    pruefe(Trips::istFruehbucher($r, $bis) && !Trips::istFruehbucher($r, $bis + 1),
        'Frühbucherrabatt gilt bis zum Ende des Stichtags, keine Sekunde länger');
    pruefe(Trips::berechnen($r, ['reisende' => [['name' => 'x']]], $bis + 1)['summe'] === 190000,
        'nach dem Stichtag voller Preis');
    pruefe(Trips::abPreis($r, $jetzt) === 180000 && Trips::abPreis($r, $bis + 1) === 190000, 'Preis „ab" folgt dem Rabatt');

    $billig = $r;
    $billig['preis_cent'] = 30000;
    $billig['fruehbucher_rabatt_cent'] = 0;
    pruefe(Trips::berechnen($billig, ['reisende' => [['name' => 'x']]], $jetzt)['anzahlung'] === 30000,
        'Anzahlung nie höher als der Reisepreis');

    $knapp = $r;
    $knapp['start'] = $tag('+5 days');
    pruefe(Trips::berechnen($knapp, ['reisende' => [['name' => 'x']]], $jetzt)['rest_faellig'] === date('Y-m-d', $jetzt),
        'Restzahlung liegt nie in der Vergangenheit');
    pruefe(Trips::berechnen($r, ['reisende' => [['name' => 'x']]], $jetzt)['rest_faellig']
        === date('Y-m-d', strtotime(substr((string) $r['start'], 0, 10) . ' -30 days')), 'Restzahlung 30 Tage vor Abreise');

    $viele = array_map(static fn ($i) => ['name' => 'Person ' . $i], range(1, 9));
    $viele[] = ['name' => '   '];
    pruefe(count(Trips::reisendeAus($viele)) === Trips::PERSONEN_MAX, 'höchstens sechs Reisende je Buchung');
    pruefe(Trips::reisendeAus([['name' => ''], ['name' => ' Ada  Lovelace ']])[0]['name'] === 'Ada Lovelace',
        'leere Namen fallen weg, Leerraum wird geglättet');

    abschnitt('Bildpfade');
    pruefe(Trips::pfad('uploads/w1/medien/bild.jpg') === 'uploads/w1/medien/bild.jpg', 'eigene Datei wird angenommen');
    pruefe(Trips::pfad('https://bilder.example/a.jpg') === 'https://bilder.example/a.jpg', 'https-Adresse wird angenommen');
    foreach (['javascript:alert(1)', '//boese.example/a.jpg', 'http://boese.example/a.jpg',
              'uploads/../config.php', 'data:image/svg+xml,<svg onload=alert(1)>', 'uploads/a b.jpg', '"><script>'] as $boese) {
        pruefe(Trips::pfad($boese) === '', 'abgelehnt: ' . $boese);
    }

    abschnitt('Zeitraum wie im Katalog');
    pruefe(Reiseseite::zeitraum('2027-04-12 00:00:00', '2027-04-19 00:00:00') === '12.–19. April 2027', 'im selben Monat');
    pruefe(Reiseseite::zeitraum('2027-03-28 00:00:00', '2027-04-04 00:00:00') === '28. März – 4. April 2027', 'über den Monatswechsel');
    pruefe(Reiseseite::zeitraum('2026-12-28 00:00:00', '2027-01-04 00:00:00') === '28. Dezember 2026 – 4. Januar 2027',
        'über den Jahreswechsel mit beiden Jahren');

    /* ============================================ Plätze und Buchungen === */

    abschnitt('Plätze zählen Personen');
    $voll = $reiseAnlegen($A, ['titel' => 'Kleine Reise', 'plaetze' => 3]);
    Tenant::setzen($A);
    [$id1, , $neu1] = Trips::anmelden((int) $voll['id'], ['reisende' => [['name' => 'A'], ['name' => 'B']], 'email' => 'paar@example.org']);
    pruefe($id1 > 0 && $neu1 && Trips::belegt((int) $voll['id']) === 2, 'eine Buchung für zwei belegt zwei Plätze');
    [$id2, $meldung2] = Trips::anmelden((int) $voll['id'], ['reisende' => [['name' => 'C'], ['name' => 'D']], 'email' => 'zwei@example.org']);
    pruefe($id2 > 0 && (string) Tenant::find('trip_signups', $id2)['status'] === 'warteliste' && Trips::belegt((int) $voll['id']) === 2
        && str_contains($meldung2, 'Warteliste'), 'reicht der Platz nicht für alle, kommt die ganze Buchung auf die Warteliste');
    [$id3, , $neu3] = Trips::anmelden((int) $voll['id'], ['reisende' => [['name' => 'A']], 'email' => 'PAAR@example.org']);
    pruefe($id3 === $id1 && !$neu3 && Tenant::count('trip_signups', 'trip_id = :t', ['t' => $voll['id']]) === 2,
        'dieselbe Adresse noch einmal: die vorhandene Buchung, keine neue');
    Tenant::update('trips', (int) $voll['id'], ['warteliste' => 0]);
    [$id4, $meldung4] = Trips::anmelden((int) $voll['id'], ['reisende' => [['name' => 'E'], ['name' => 'F']], 'email' => 'e@example.org']);
    pruefe($id4 === 0 && str_contains($meldung4, 'nur noch 1 Platz'), 'ohne Warteliste: abgelehnt mit der Zahl der freien Plätze');
    Tenant::update('trips', (int) $voll['id'], ['start' => $tag('-1 day'), 'ende' => $tag('+6 days')]);
    [$id5] = Trips::anmelden((int) $voll['id'], ['reisende' => [['name' => 'G']], 'email' => 'g@example.org']);
    pruefe($id5 === 0, 'eine begonnene Reise nimmt keine Buchung mehr an');
    Tenant::setzen(0);

    abschnitt('Teilnehmerliste');
    Tenant::setzen($A);
    Trips::anmelden((int) $r['id'], ['reisende' => [['name' => '=HYPERLINK("http://boese.example","klick")']], 'email' => 'formel@example.org']);
    $csv = Trips::csv(Tenant::find('trips', (int) $r['id']), 'teilnehmer');
    pruefe(str_contains($csv, "'=HYPERLINK") && !preg_match('/(^|;)=HYPERLINK/m', $csv),
        'ein Name, der wie eine Formel beginnt, wird in der CSV entschärft');
    Tenant::setzen(0);

    /* =================================================== Die Website === */

    $reiseA   = $reiseAnlegen($A, ['titel' => 'Golfwoche Mallorca', 'kurztext' => 'Sieben Nächte am Platz.',
        'bild' => 'assets/demo/reisen/mallorca-kueste.svg',
        'bilder' => Util::json(['assets/demo/reisen/gruen.svg', 'javascript:alert(1)', 'assets/demo/reisen/abend.svg'])]);
    $portugal = $reiseAnlegen($A, ['titel' => 'Algarve im Herbst', 'land' => 'Portugal', 'ziel' => 'Lagos']);
    $geplant  = $reiseAnlegen($A, ['titel' => 'Geheimreise Schottland', 'status' => 'geplant', 'land' => 'Schottland']);
    $vorbei   = $reiseAnlegen($A, ['titel' => 'Vergangene Reise', 'start' => $tag('-30 days'), 'ende' => $tag('-23 days')]);
    $abgesagt = $reiseAnlegen($A, ['titel' => 'Abgesagte Reise', 'status' => 'abgesagt']);
    $boese    = $reiseAnlegen($A, ['titel' => 'Reise </script><script>alert(1)</script>', 'kurztext' => '<img src=x onerror=alert(1)>']);
    $fremd    = $reiseAnlegen($B, ['titel' => 'Beta-Reise nach Irland', 'land' => 'Irland']);
    $klein    = $reiseAnlegen($C, ['titel' => 'Reise ohne Modul']);
    $eng      = $reiseAnlegen($A, ['titel' => 'Enge Reise', 'plaetze' => 4]);

    $server = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', GP_ROOT],
        [0 => ['pipe', 'r'], 1 => ['file', $ordner . '/server.log', 'a'], 2 => ['file', $ordner . '/server.log', 'a']],
        $rohre, GP_ROOT, ['GP_CONFIG' => $ordner . '/config.php', 'PATH' => getenv('PATH')]);
    for ($i = 0; $i < 50; $i++) {
        if (@fsockopen('127.0.0.1', $port)) {
            break;
        }
        usleep(100000);
    }

    $basis = 'http://127.0.0.1:' . $port;
    $gast  = new Besucher($basis, $ordner);
    $anna  = (new Besucher($basis, $ordner))->anmelden('anna@alpha.example', $pw);
    $bert  = (new Besucher($basis, $ordner))->anmelden('bert@beta.example', $pw);

    abschnitt('Katalog');
    $gast->holen('/reisen.php?w=reise-alpha');
    pruefe($gast->status === 200 && str_contains($gast->inhalt, 'Golfwoche Mallorca') && str_contains($gast->inhalt, 'Algarve im Herbst'),
        'veröffentlichte, kommende Reisen stehen im Katalog');
    pruefe(!str_contains($gast->inhalt, 'Geheimreise') && !str_contains($gast->inhalt, 'Vergangene Reise')
        && !str_contains($gast->inhalt, 'Abgesagte Reise'), 'geplante, vergangene und abgesagte nicht');
    pruefe(!str_contains($gast->inhalt, 'Beta-Reise'), 'keine Reise einer anderen Instanz');
    pruefe(str_contains($gast->inhalt, 'Frühbucher bis') && str_contains($gast->inhalt, 'class="preis-alt"'),
        'Frühbucher als Marke, alter Preis durchgestrichen');
    pruefe(!str_contains($gast->inhalt, '<img src=x') && !str_contains($gast->inhalt, '<script>alert(1)'), 'Texte maskiert');
    $gast->holen('/reisen.php?w=reise-alpha&land=Portugal');
    pruefe(str_contains($gast->inhalt, 'Algarve im Herbst') && !str_contains($gast->inhalt, 'Golfwoche Mallorca')
        && str_contains($gast->inhalt, 'noindex'), 'Filter nach Land, gefilterte Fassung nicht im Suchindex');
    $gast->holen('/reisen.php?w=reise-alpha&land=Irland');
    pruefe(str_contains($gast->inhalt, 'Golfwoche Mallorca') && str_contains($gast->inhalt, 'Algarve im Herbst'),
        'ein Land, das es hier nicht gibt, filtert nicht ins Leere');
    $gast->holen('/reisen.php?w=reise-klein');
    pruefe($gast->status === 404, 'ohne das Modul im Paket: kein Katalog');
    $gast->holen('/reise.php?w=reise-klein&r=' . rawurlencode((string) $klein['slug']));
    pruefe($gast->status === 404, 'ohne das Modul im Paket: keine Reiseseite');

    abschnitt('Reiseseite');
    $url = '/reise.php?w=reise-alpha&r=' . rawurlencode((string) $reiseA['slug']);
    $gast->holen($url);
    pruefe($gast->status === 200 && str_contains($gast->inhalt, 'reisekopf') && str_contains($gast->inhalt, 'data-reiseformular'),
        'Seite mit Kopfbild und Buchungsformular');
    pruefe(substr_count($gast->inhalt, 'data-bildfenster="galerie"') === 2, 'Galerie ohne den abgelehnten Bildpfad');
    pruefe(str_contains($gast->inhalt, '"@type":"TouristTrip"') && !str_contains($gast->inhalt, 'noindex'),
        'strukturierte Daten, für Suchmaschinen freigegeben');
    $gast->holen('/reise.php?w=reise-alpha&r=' . rawurlencode((string) $boese['slug']));
    preg_match('#<script type="application/ld\+json">(.*?)</script>#s', $gast->inhalt, $ld);
    pruefe($gast->status === 200 && !str_contains($gast->inhalt, '<script>alert(1)') && isset($ld[1])
        && json_decode(end($ld) ?: '', true) !== null, 'ein Titel mit </script> bricht weder Seite noch strukturierte Daten auf');
    $gast->holen('/reise.php?w=reise-alpha&r=' . rawurlencode((string) $fremd['slug']));
    pruefe($gast->status === 404, 'die Reise einer anderen Instanz über diese Adresse: 404');
    $gast->holen('/reise.php?w=reise-alpha&r=' . rawurlencode((string) $vorbei['slug']));
    pruefe($gast->status === 200 && !str_contains($gast->inhalt, 'data-reiseformular') && str_contains($gast->inhalt, 'bereits begonnen'),
        'vergangene Reise: lesbar, aber nicht buchbar');

    abschnitt('Vorschau');
    $geplantUrl = '/reise.php?w=reise-alpha&r=' . rawurlencode((string) $geplant['slug']);
    $gast->holen($geplantUrl);
    pruefe($gast->status === 404, 'geplante Reise: für Besucher 404');
    $anna->holen($geplantUrl);
    pruefe($anna->status === 200 && str_contains($anna->inhalt, 'Vorschau') && str_contains($anna->inhalt, 'noindex')
        && !str_contains($anna->inhalt, 'TouristTrip'), 'für das eigene Team: Vorschau, nicht für Suchmaschinen');
    $bert->holen($geplantUrl);
    pruefe($bert->status === 404, 'angemeldet bei einer anderen Instanz: 404');
    $anna->holen($geplantUrl, ['aktion' => 'anmelden', 'begonnen' => time() - 10, 'einwilligung' => '1',
        'reisende' => [['vorname' => 'Anna', 'nachname' => 'Alpha']], 'email' => 'vorschau@example.org']);
    pruefe((int) DB::value('SELECT COUNT(*) FROM trip_signups WHERE trip_id = :t', ['t' => $geplant['id']]) === 0,
        'in der Vorschau wird nicht gebucht');

    abschnitt('Preis vom Server');
    $gast->holen($url, ['aktion' => 'rechnen', 'format' => 'json', 'personen' => 2, 'zimmer' => 'ez',
        'reisende' => [['vorname' => 'Ada', 'golfer' => '1'], ['golfer' => '0']], 'extra' => [0 => 9]]);
    $erwartet = Util::geldKurz(190000 + 140000 + 60000 + 18000 - 20000, '€');
    pruefe($gast->status === 200 && str_contains((string) ($gast->json['html'] ?? ''), $erwartet)
        && ($gast->json['knopf'] ?? '') === 'Buchung absenden', 'Rechnung als JSON: zwei Personen zählen, auch ohne zweiten Namen');
    $gast->holen('/reise.php?w=reise-alpha&r=' . rawurlencode((string) $eng['slug']), ['aktion' => 'rechnen', 'format' => 'json', 'personen' => 6]);
    pruefe(str_contains((string) ($gast->json['html'] ?? ''), 'Warteliste') && ($gast->json['knopf'] ?? '') === 'Auf die Warteliste',
        'mehr Personen als freie Plätze: Hinweis und Knopf nennen die Warteliste');

    abschnitt('Buchen über die Website');
    $buchen = static fn (array $mehr = []): array => $mehr + [
        'aktion' => 'anmelden', 'begonnen' => time() - 10, 'einwilligung' => '1',
        'reisende' => [['vorname' => 'Carla', 'nachname' => 'Probe', 'golfer' => '1', 'hcp' => '18,4'],
                       ['vorname' => 'Ben', 'nachname' => 'Probe', 'golfer' => '0']],
        'zimmer' => 'dz', 'extra' => [0 => 50, 1 => 1, 7 => 3],
        'email' => 'carla@example.org', 'telefon' => '0721 123',
        /* Felder, die es nicht gibt – und die nichts bewirken dürfen. */
        'preis_cent' => 1, 'summe' => 1, 'rabatt_cent' => 999999, 'status' => 'bestaetigt',
    ];
    $gast->holen($url, $buchen(['website' => 'http://spam.example']));
    pruefe(DB::value("SELECT COUNT(*) FROM trip_signups WHERE email = 'carla@example.org'") == 0, 'Honigtopf ausgefüllt: keine Buchung');
    $gast->holen($url, $buchen(['einwilligung' => '']));
    pruefe(DB::value("SELECT COUNT(*) FROM trip_signups WHERE email = 'carla@example.org'") == 0
        && str_contains($gast->inhalt, 'Einwilligung') && str_contains($gast->inhalt, 'value="Carla"'),
        'ohne Einwilligung: keine Buchung, Eingaben bleiben stehen');
    $gast->holen($url, $buchen());
    $zeile = DB::one("SELECT * FROM trip_signups WHERE email = 'carla@example.org'");
    /* 1.900 + 1.400 + 2 × 90 (50 auf 2 gekappt) + 120 − 2 × 100 */
    pruefe($zeile !== null && (int) $zeile['preis_cent'] === 190000 + 140000 + 18000 + 12000 - 20000,
        'gespeichert wird der Preis des Servers – kein Formularfeld verbiegt ihn');
    pruefe($zeile !== null && (string) $zeile['status'] === 'angemeldet' && (int) $zeile['personen'] === 2
        && (int) $zeile['workspace_id'] === $A && (int) $zeile['anzahlung_cent'] === 100000, 'Status, Personen, Mandant und Anzahlung stimmen');
    pruefe(str_contains($gast->inhalt, 'Deine Buchung ist da') && str_contains($gast->inhalt, 'So geht es weiter'),
        'Bestätigungsseite mit den nächsten Schritten');
    $kunde = $zeile !== null ? DB::one('SELECT * FROM customers WHERE id = :i', ['i' => $zeile['customer_id']]) : null;
    pruefe($kunde !== null && (int) $kunde['workspace_id'] === $A, 'Kunde im richtigen Mandanten angelegt');
    $mail = DB::one("SELECT * FROM communications WHERE betreff LIKE 'Deine Buchung:%' ORDER BY id DESC");
    pruefe($mail !== null && (int) $mail['customer_id'] === (int) ($kunde['id'] ?? -1) && str_contains((string) $mail['text'], 'Gesamt: 3.400,00 €'),
        'E-Mail mit der Rechnung, in der Kundenakte abgelegt');
    $mails = (int) DB::value('SELECT COUNT(*) FROM communications');
    $gast->holen($url, $buchen());
    pruefe(str_contains($gast->inhalt, 'liegt schon vor') && DB::value("SELECT COUNT(*) FROM trip_signups WHERE email = 'carla@example.org'") == 1
        && (int) DB::value('SELECT COUNT(*) FROM communications') === $mails, 'zweimal abgeschickt: keine zweite Buchung, keine zweite E-Mail');
    $gast->holen('/reise.php?w=reise-beta&r=' . rawurlencode((string) $reiseA['slug']), $buchen(['email' => 'quer@example.org']));
    pruefe($gast->status === 404 && DB::value("SELECT COUNT(*) FROM trip_signups WHERE email = 'quer@example.org'") == 0,
        'über die Adresse einer anderen Instanz lässt sich nicht buchen');

    abschnitt('Kundenportal');
    $token = bin2hex(random_bytes(16));
    DB::update('customers', ['portal_token' => $token, 'portal_token_bis' => date('Y-m-d H:i:s', strtotime('+1 day'))],
        'id = :i', ['i' => (int) ($kunde['id'] ?? 0)]);
    $carla = new Besucher($basis, $ordner);
    $carla->holen('/portal/?w=reise-alpha&t=' . $token);
    $carla->holen('/portal/');
    pruefe(str_contains($carla->inhalt, 'pt-reisehinweis') && str_contains($carla->inhalt, 'Golfwoche Mallorca'),
        'Startseite nennt die nächste Reise');
    $carla->holen('/portal/?ansicht=reisen');
    pruefe(str_contains($carla->inhalt, 'Meine Reisen') && str_contains($carla->inhalt, 'Ben Probe')
        && str_contains($carla->inhalt, 'wartet auf Bestätigung') && str_contains($carla->inhalt, '3.400 €'),
        'Meine Reisen: Reisende, Status und Betrag');
    pruefe(!str_contains($carla->inhalt, 'paar@example.org') && !str_contains($carla->inhalt, 'Kleine Reise'),
        'nur die eigenen Buchungen');
    Tenant::setzen($B);
    $fremdKunde = Customers::speichern(['vorname' => 'Fritz', 'nachname' => 'Fremd', 'email' => 'fritz@beta.example', 'status' => 'aktiv']);
    Tenant::setzen(0);
    DB::update('trip_signups', ['customer_id' => $fremdKunde], "email = 'paar@example.org'");
    $t2 = bin2hex(random_bytes(16));
    DB::update('customers', ['portal_token' => $t2, 'portal_token_bis' => date('Y-m-d H:i:s', strtotime('+1 day'))], 'id = :i', ['i' => $fremdKunde]);
    $fritz = new Besucher($basis, $ordner);
    $fritz->holen('/portal/?w=reise-beta&t=' . $t2);
    $fritz->holen('/portal/?ansicht=reisen');
    pruefe(!str_contains($fritz->inhalt, 'Kleine Reise') && str_contains($fritz->inhalt, 'sobald du eine gebucht hast'),
        'eine Buchung aus einer anderen Instanz mit fremder Kundennummer erscheint nicht');

    abschnitt('Baustein auf der Startseite');
    Tenant::setzen($A);
    $block = Bloecke::neu('reisen');
    $block['daten']['anzahl'] = 1;
    $seite = Pages::speichern(['titel' => 'Reisen', 'bloecke' => Util::json([$block]), 'status' => 'veroeffentlicht', 'art' => 'seite']);
    $seitenSlug = (string) Tenant::find('pages', $seite)['slug'];
    Tenant::setzen(0);
    $gast->holen('/site.php?w=reise-alpha&s=' . rawurlencode($seitenSlug));
    pruefe(substr_count($gast->inhalt, 'class="reisekachel"') === 1 && str_contains($gast->inhalt, 'Alle Reisen ansehen'),
        'Baustein zeigt die Kachel und verweist auf den Katalog, wenn es mehr gibt');

    abschnitt('Editor');
    $anna->holen('/app/reise.php?id=' . (int) $fremd['id']);
    pruefe($anna->status === 302, 'Reise einer anderen Instanz im Editor: weitergeleitet');
    $anna->holen('/app/reise.php?id=' . (int) $fremd['id'] . '&export=teilnehmer');
    pruefe(!str_contains($anna->inhalt, 'Name;Golfer'), 'fremde Teilnehmerliste: kein Export');

    abschnitt('Demo');
    Demo::anlegen(['email' => 'demo-reisen@example.org']);
    $demo = (int) DB::value("SELECT id FROM workspaces WHERE slug = 'demo'");
    Tenant::setzen($demo);
    $demoReisen = Tenant::all('trips', "status = 'veroeffentlicht'");
    $ok = count($demoReisen) === 2;
    foreach ($demoReisen as $dr) {
        $ok = $ok && Trips::pfad((string) $dr['bild']) !== '' && is_file(GP_ROOT . '/' . Trips::pfad((string) $dr['bild']))
            && Trips::galerie($dr) !== [] && Trips::golfplaetze((string) $dr['golfplaetze']) !== []
            && Trips::belegt((int) $dr['id']) <= (int) $dr['plaetze'];
    }
    pruefe($ok, 'Demo-Reisen mit Bildern, Galerie und Plätzen, nicht überbucht');
    $zuZweit = Tenant::count('trip_signups', 'personen = 2');
    pruefe($zuZweit > 0, 'in der Demo reisen manche zu zweit');
    Tenant::setzen(0);
} finally {
    if (isset($server)) {
        proc_terminate($server);
        proc_close($server);
    }
    if (is_dir($ordner)) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($ordner, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($ordner);
    }
    foreach ([$A ?? 0, $B ?? 0, $C ?? 0, $demo ?? 0] as $w) {
        foreach ([GP_ROOT . '/uploads/w' . $w, GP_ROOT . '/data/privat/w' . $w] as $rest) {
            if ($w > 900000 && is_dir($rest) && count(scandir($rest)) === 2) {
                @rmdir($rest);
            }
        }
    }
}

final class Besucher
{
    private string $kekse;
    public int $status = 0;
    public string $inhalt = '';
    public array $json = [];
    public static string $letzte = '';

    public function __construct(private string $basis, string $ordner)
    {
        $this->kekse = $ordner . '/kekse-' . bin2hex(random_bytes(4));
    }

    public function holen(string $pfad, ?array $post = null): self
    {
        $c = curl_init($this->basis . $pfad);
        curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_COOKIEJAR => $this->kekse, CURLOPT_COOKIEFILE => $this->kekse, CURLOPT_TIMEOUT => 20]);
        if ($post !== null) {
            curl_setopt($c, CURLOPT_POST, true);
            curl_setopt($c, CURLOPT_POSTFIELDS, http_build_query($post));
        }
        $this->inhalt = (string) curl_exec($c);
        $this->status = (int) curl_getinfo($c, CURLINFO_RESPONSE_CODE);
        curl_close($c);
        $this->json = (array) (json_decode($this->inhalt, true) ?? []);
        self::$letzte = $this->status . ' ' . $this->inhalt;
        return $this;
    }

    public function anmelden(string $email, string $pw): self
    {
        $this->holen('/login.php');
        preg_match('/name="_csrf" value="([^"]+)"/', $this->inhalt, $m);
        $this->holen('/login.php', ['_csrf' => $m[1] ?? '', 'email' => $email, 'passwort' => $pw]);
        return $this->holen('/app/kunden.php');
    }
}

fwrite(STDOUT, PHP_EOL . $ergebnisse['ok'] . ' bestanden, ' . $ergebnisse['fehl'] . ' fehlgeschlagen.' . PHP_EOL);
exit($ergebnisse['fehl'] === 0 ? 0 : 1);
