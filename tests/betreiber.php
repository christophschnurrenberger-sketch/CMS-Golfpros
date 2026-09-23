<?php
/**
 * Prüfungen der Betreiberzentrale – gegen eine Wegwerf-Datenbank.
 *
 *   php tests/betreiber.php
 *
 * Legt in einem temporären Ordner eine eigene config.php und eine leere
 * SQLite-Datenbank an, startet dafür einen eigenen PHP-Entwicklungsserver
 * und prüft über HTTP, was ein Browser täte. Die echte Datenbank der
 * Anlage wird nicht angefasst. Am Ende wird alles wieder weggeräumt.
 *
 * Geprüft wird, was schiefgehen darf, ohne dass es jemand merkt:
 * Rechte, Mandantengrenzen, Protokoll, Support Mode, Löschen, Migration.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
define('GP_ROOT', dirname(__DIR__));

/* --------------------------------------------------- Wegwerf-Umgebung --- */

$ordner = sys_get_temp_dir() . '/teepilot-test-' . bin2hex(random_bytes(4));
mkdir($ordner, 0700, true);
$port = 18000 + random_int(0, 999);
$geheim = bin2hex(random_bytes(32));
file_put_contents($ordner . '/config.php', '<?php return ' . var_export([
    'db' => ['driver' => 'sqlite', 'path' => $ordner . '/test.sqlite'],
    'secret' => $geheim,
    'base_url' => 'http://127.0.0.1:' . $port,
    'demo_zugang' => false,
    'mail' => ['from_name' => 'TeePilot Test', 'from_email' => 'test@example.org', 'transport' => 'keiner'],
    'stripe' => ['public_key' => '', 'secret_key' => 'sk_test_GEHEIMNIS_DARF_NIE_ERSCHEINEN', 'webhook_secret' => ''],
    'debug' => false,
    'rechnungsablage' => $ordner . '/rechnungen',
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
}
function abschnitt(string $titel): void
{
    fwrite(STDOUT, PHP_EOL . $titel . PHP_EOL);
}

/* ------------------------------------------------------------ Migration --- */

abschnitt('Migration auf eine Datenbank mit Bestandskunden (Schema 7 → 8)');
/*
 * Ein Stand wie vor der Betreiberzentrale: Die neuen Tabellen und
 * Spalten gibt es nicht, die Schemaversion ist 7. Darin zwei Betriebe
 * mit Kunden, Terminen und Protokoll. Nach der Migration muss jede Zeile
 * noch da sein.
 */
$pdo = DB::pdo();
foreach (['betreiber', 'betreiber_log', 'pakete', 'abos', 'betreiber_notizen'] as $t) {
    $pdo->exec('DROP TABLE IF EXISTS ' . $t);
}
$pdo->exec('DROP INDEX IF EXISTS idx_workspaces_status');
$pdo->exec('DROP INDEX IF EXISTS idx_audit_log_aktion');
$pdo->exec('ALTER TABLE workspaces DROP COLUMN status');
$pdo->exec('ALTER TABLE workspaces DROP COLUMN letzte_aktivitaet');
$pdo->exec('ALTER TABLE audit_log DROP COLUMN betreiber_id');
DB::update('settings', ['wert' => '7'], "workspace_id = 0 AND schluessel = 'schema_version'");

$jetzt = date('Y-m-d H:i:s');
/* Hohe IDs: Beim Löschen einer Instanz verschwinden uploads/w<ID> und
   data/privat/w<ID> – im echten Anlagenordner. Mit IDs weit jenseits
   jeder echten Instanz trifft der Test nie einen fremden Ordner. */
$start = 900000 + random_int(0, 90000);
DB::insert('workspaces', ['id' => $start, 'slug' => 'platzhalter', 'name' => 'x', 'erstellt' => $jetzt]);
DB::delete('workspaces', 'id = :i', ['i' => $start]);
$alt = [];
foreach ([['alt-eins', 'Golfschule Alt Eins', 1, 'pro'], ['alt-zwei', 'Golfschule Alt Zwei', 0, 'business']] as [$slug, $name, $aktiv, $plan]) {
    $w = DB::insert('workspaces', ['slug' => $slug, 'name' => $name, 'plan' => $plan, 'aktiv' => $aktiv, 'demo' => 0,
                                   'erstellt' => date('Y-m-d H:i:s', strtotime('-40 days'))]);
    $u = DB::insert('users', ['workspace_id' => $w, 'email' => $slug . '@example.org', 'name' => 'Inhaber ' . $slug,
                              'rolle' => 'owner', 'passwort' => password_hash('Test!2026alt', PASSWORD_DEFAULT), 'aktiv' => 1,
                              'letzter_login' => date('Y-m-d H:i:s', strtotime('-3 days')), 'erstellt' => $jetzt]);
    for ($i = 0; $i < 5; $i++) {
        DB::insert('customers', ['workspace_id' => $w, 'vorname' => 'Kunde' . $i, 'nachname' => $slug, 'email' => "k$i@$slug.example",
                                 'status' => 'aktiv', 'erstellt' => $jetzt]);
    }
    DB::insert('audit_log', ['workspace_id' => $w, 'user_id' => $u, 'aktion' => 'erstellt', 'objekt' => 'customer',
                             'objekt_id' => 1, 'beschreibung' => 'Kunde angelegt', 'ip' => '', 'erstellt' => $jetzt]);
    $alt[$slug] = $w;
}
$zaehlen = static function (): array {
    $aus = [];
    foreach (DB::all("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'") as $t) {
        $aus[$t['name']] = DB::int('SELECT COUNT(*) FROM ' . $t['name']);
    }
    return $aus;
};
$vorher = $zaehlen();
Schema::migrate();
DB::update('settings', ['wert' => (string) Schema::VERSION], "workspace_id = 0 AND schluessel = 'schema_version'");
$nachher = $zaehlen();
$verloren = [];
foreach ($vorher as $t => $n) {
    if (($nachher[$t] ?? -1) !== $n && $t !== 'settings') {
        $verloren[] = "$t: $n → " . ($nachher[$t] ?? 'fehlt');
    }
}
pruefe($verloren === [], 'keine Zeile verloren' . ($verloren ? ' – ' . implode(', ', $verloren) : ''));
pruefe(DB::value('SELECT status FROM workspaces WHERE id = :i', ['i' => $alt['alt-eins']]) === 'aktiv', 'aktive Instanz wird „aktiv"');
pruefe(DB::value('SELECT status FROM workspaces WHERE id = :i', ['i' => $alt['alt-zwei']]) === 'gesperrt', 'abgeschaltete Instanz wird „gesperrt", nicht gelöscht');
pruefe((string) DB::value('SELECT letzte_aktivitaet FROM workspaces WHERE id = :i', ['i' => $alt['alt-eins']]) !== '', 'letzte Aktivität aus echten Daten nachgetragen');
pruefe(DB::int('SELECT COUNT(*) FROM abos') === 0, 'Bestandsinstanzen bekommen keinen erfundenen Vertrag');
pruefe(DB::int('SELECT COUNT(*) FROM pakete') === 4, 'die vier bisherigen Tarife stehen als Pakete in der Datenbank');
pruefe((int) (Pakete::finden('pro')['preis_monat_cent'] ?? 0) === 5900, 'Preis von Pro unverändert übernommen');
pruefe(Pakete::erlaubt('video', 'business') && !Pakete::erlaubt('video', 'pro'), 'Freischaltung wie bisher: Videoanalyse ab Business');
DB::update('workspaces', ['status' => 'aktiv', 'aktiv' => 1], 'id = :i', ['i' => $alt['alt-zwei']]);

/* ------------------------------------------------- Unveränderliches Log --- */

abschnitt('Audit-Log ist nur zum Anhängen');
$logId = Betreiberlog::schreiben('SETTINGS_CHANGED', ['vorher' => ['passwort' => 'x', 'reset_token' => 'y', 'wert' => 1]]);
$eintrag = Betreiberlog::eintrag($logId);
pruefe(!str_contains((string) $eintrag['vorher'], 'passwort') && !str_contains((string) $eintrag['vorher'], 'reset_token'),
    'Passwörter und Tokens werden aus Vorher/Nachher entfernt');
$geaendert = true;
try {
    DB::pdo()->exec("UPDATE betreiber_log SET aktion = 'X' WHERE id = " . $logId);
} catch (Throwable $e) {
    $geaendert = false;
}
pruefe(!$geaendert, 'UPDATE auf betreiber_log wird von der Datenbank abgewiesen');
$geloescht = true;
try {
    DB::pdo()->exec('DELETE FROM betreiber_log WHERE id = ' . $logId);
} catch (Throwable $e) {
    $geloescht = false;
}
pruefe(!$geloescht, 'DELETE auf betreiber_log wird von der Datenbank abgewiesen');

/* ------------------------------------------------------------ Bestand --- */

/* Zwei Instanzen A und B, wie sie der Assistent anlegt, mit Passwörtern
   für die Prüfung. Dazu ein Betreiber. */
$a = Instanzen::anlegen(['name' => 'Instanz Alpha', 'inhaber' => 'Anna Alpha', 'email' => 'anna@alpha.example',
    'paket' => 'starter', 'laufzeit' => 'monat', 'slug' => 'alpha', 'club' => 'GC Alpha']);
$b = Instanzen::anlegen(['name' => 'Instanz Beta', 'inhaber' => 'Bert Beta', 'email' => 'bert@beta.example',
    'paket' => 'academy', 'laufzeit' => 'test', 'slug' => 'beta']);
$A = $a['id'];
$Bid = $b['id'];
pruefe($A > 0 && $Bid > 0, 'zwei Instanzen angelegt (#' . $A . ', #' . $Bid . ')');
$pw = 'Test!2026sicher';
DB::update('users', ['passwort' => password_hash($pw, PASSWORD_DEFAULT)], "email IN ('anna@alpha.example', 'bert@beta.example')");
$annaId = DB::int("SELECT id FROM users WHERE email = 'anna@alpha.example'");
$kundeB = DB::insert('customers', ['workspace_id' => $Bid, 'vorname' => 'Geheim', 'nachname' => 'Betakundin',
    'email' => 'geheim@beta-kunde.example', 'status' => 'aktiv', 'erstellt' => $jetzt]);
[$betreiberId, $betreiberToken] = Betreiber::anlegen('chef@betrieb.example', 'Chefin Betrieb', 'master_admin', 'Test');
DB::update('betreiber', ['passwort' => password_hash($pw, PASSWORD_DEFAULT), 'reset_token' => ''], 'id = :i', ['i' => $betreiberId]);
pruefe(DB::int('SELECT COUNT(*) FROM abos WHERE workspace_id = :w', ['w' => $A]) === 1, 'Instanz mit Vertrag angelegt');
pruefe((string) DB::value('SELECT status FROM workspaces WHERE id = :i', ['i' => $Bid]) === 'test', 'Testphase ergibt Status „Testphase"');
pruefe(!str_contains((string) DB::value("SELECT reset_token FROM users WHERE email = 'anna@alpha.example'"), 'x')
    && strlen((string) DB::value("SELECT reset_token FROM users WHERE email = 'anna@alpha.example'")) === 64,
    'Einladungstoken steht nur als SHA-256-Abdruck in der Datenbank');
$doppelt = Instanzen::anlegen(['name' => 'Doppelt', 'inhaber' => 'X', 'email' => 'anna@alpha.example', 'paket' => 'starter',
    'laufzeit' => 'monat', 'slug' => 'doppelt']);
pruefe($doppelt['id'] === 0 && isset($doppelt['fehler']['email']), 'zweite Instanz mit derselben Inhaber-Adresse wird abgewiesen');
pruefe(DB::int("SELECT COUNT(*) FROM workspaces WHERE slug = 'doppelt'") === 0, 'dabei bleibt keine halbe Instanz zurück');

/* ---------------------------------------------------------- Rechnungen --- */

abschnitt('Rechnungen an Instanzen: Rechnen, Ausstellen, Unveränderlichkeit');
$b = Betreiberrechnungen::berechnen([
    ['text' => 'A', 'menge_hundertstel' => 300, 'einzel_cent' => 3333, 'steuersatz' => 19],
], 'regel');
pruefe($b['netto'] === 9999 && $b['steuer'] === 1900 && $b['brutto'] === 11899, '3 × 33,33 € + 19 % = 118,99 € (Steuer auf die Nettosumme gerundet)');
$b = Betreiberrechnungen::berechnen([
    ['text' => 'A', 'menge_hundertstel' => 100, 'einzel_cent' => 2900, 'steuersatz' => 19],
    ['text' => 'B', 'menge_hundertstel' => 100, 'einzel_cent' => 1000, 'steuersatz' => 7],
], 'regel');
pruefe($b['steuer'] === 551 + 70 && count($b['steuern']) === 2, 'zwei Steuersätze werden getrennt ausgewiesen');
pruefe(Betreiberrechnungen::berechnen([['text' => 'A', 'menge_hundertstel' => 100, 'einzel_cent' => 2900, 'steuersatz' => 19]], 'klein')['steuer'] === 0,
    'Kleinunternehmer: keine Umsatzsteuer');
pruefe(Betreiberrechnungen::steuerfallVorschlag(['land' => 'AT', 'ust_id' => 'ATU12345678']) === 'rc'
    && Betreiberrechnungen::steuerfallVorschlag(['land' => 'CH', 'ust_id' => '']) === 'drittland'
    && Betreiberrechnungen::steuerfallVorschlag(['land' => 'DE', 'ust_id' => '']) === 'regel', 'Steuerfall-Vorschlag nach Land und USt-IdNr.');

$empfaengerB = ['firma' => 'Golfschule Beta GmbH', 'name' => 'Bert Beta', 'strasse' => 'Fairway 1', 'plz' => '10115',
                'ort' => 'Berlin', 'land' => 'DE', 'email' => 'bert@beta.example'];
$pos = [['text' => 'TeePilot Academy – Oktober', 'menge_hundertstel' => 100, 'einheit' => 'Monat', 'einzel_cent' => 19900, 'steuersatz' => 19]];
[$r1] = Betreiberrechnungen::entwurfSpeichern(0, $Bid, ['empfaenger' => $empfaengerB, 'leistung_von' => '2026-10-01',
    'leistung_bis' => '2026-10-31'], $pos);
[$ok] = Betreiberrechnungen::ausstellen($r1);
pruefe(!$ok, 'ohne vollständigen Rechnungsabsender wird nicht ausgestellt');
foreach (['rg_firma' => 'TeePilot Betrieb GmbH', 'rg_strasse' => 'Teeweg 1', 'rg_plz' => '76131', 'rg_ort' => 'Karlsruhe',
          'rg_steuernummer' => '35/123/45678', 'rg_email' => 'rechnung@betrieb.example'] as $k => $v) {
    Plattform::setzen($k, $v);
}
[$ok, $meldung] = Betreiberrechnungen::ausstellen($r1);
$rechnung1 = Betreiberrechnungen::finden($r1);
pruefe($ok && preg_match('/^TP-\d{4}-0001$/', (string) $rechnung1['nummer']) === 1, 'ausgestellt mit Nummer ' . $rechnung1['nummer']);
pruefe((int) $rechnung1['brutto_cent'] === 23681 && (string) $rechnung1['status'] === 'offen', '199,00 € + 19 % = 236,81 €, Status offen');
pruefe(Betreiberrechnungen::dateiInhalt($rechnung1) !== null && str_starts_with((string) Betreiberrechnungen::dateiInhalt($rechnung1), '%PDF-'),
    'PDF abgelegt und gegen seinen Abdruck geprüft');
pruefe(str_contains((string) $rechnung1['absender'], 'TeePilot Betrieb GmbH'), 'Absender ist in der Rechnung eingefroren');
$abgewiesen = static function (string $sql): bool {
    try {
        DB::pdo()->exec($sql);
        return false;
    } catch (Throwable $e) {
        return true;
    }
};
pruefe($abgewiesen('UPDATE betreiber_rechnungen SET brutto_cent = 1 WHERE id = ' . $r1), 'Betrag einer ausgestellten Rechnung: Datenbank weist Änderung ab');
pruefe($abgewiesen("UPDATE betreiber_rechnungen SET nummer = 'X' WHERE id = " . $r1), 'Nummer: Änderung abgewiesen');
pruefe($abgewiesen("UPDATE betreiber_rechnungen SET status = 'entwurf' WHERE id = " . $r1), 'zurück zum Entwurf: abgewiesen');
pruefe($abgewiesen('DELETE FROM betreiber_rechnungen WHERE id = ' . $r1), 'Löschen: abgewiesen');
pruefe($abgewiesen('INSERT INTO betreiber_rechnungspositionen (rechnung_id, text) VALUES (' . $r1 . ", 'x')"), 'neue Position: abgewiesen');
pruefe($abgewiesen('DELETE FROM betreiber_rechnungspositionen WHERE rechnung_id = ' . $r1), 'Position löschen: abgewiesen');
[$bearbeitet, $fehler] = Betreiberrechnungen::entwurfSpeichern($r1, $Bid, ['empfaenger' => $empfaengerB], $pos);
pruefe($bearbeitet === 0, 'die Anwendung bietet keinen Weg zur Änderung');
[$ok] = Betreiberrechnungen::bezahltSetzen($r1, true, '2026-10-05');
pruefe($ok && (string) Betreiberrechnungen::finden($r1)['status'] === 'bezahlt', 'Zahlungseingang vermerkt (erlaubte Änderung)');

[$r2] = Betreiberrechnungen::entwurfSpeichern(0, $Bid, ['empfaenger' => $empfaengerB, 'leistung_von' => '2026-11-01'], $pos);
Betreiberrechnungen::ausstellen($r2);
pruefe(str_ends_with((string) Betreiberrechnungen::finden($r2)['nummer'], '-0002'), 'nächste Rechnung: fortlaufend -0002');
[$ok, , $stornoId] = Betreiberrechnungen::stornieren($r2, 'Test');
$storno = Betreiberrechnungen::finden($stornoId);
pruefe($ok && str_ends_with((string) $storno['nummer'], '-0003') && (int) $storno['brutto_cent'] === -23681 && (string) $storno['art'] === 'storno',
    'Storno: eigene Nummer -0003, negativer Betrag');
pruefe((string) Betreiberrechnungen::finden($r2)['status'] === 'storniert', 'die stornierte Rechnung ist als storniert markiert');
[$entwurfB] = Betreiberrechnungen::entwurfSpeichern(0, $Bid, ['empfaenger' => $empfaengerB], $pos);

/* ------------------------------------------------------------- Server --- */

$server = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', GP_ROOT],
    [0 => ['pipe', 'r'], 1 => ['file', $ordner . '/server.log', 'a'], 2 => ['file', $ordner . '/server.log', 'a']],
    $rohre, GP_ROOT, ['GP_CONFIG' => $ordner . '/config.php', 'PATH' => getenv('PATH')]);
for ($i = 0; $i < 50; $i++) {
    if (@fsockopen('127.0.0.1', $port)) {
        break;
    }
    usleep(100000);
}

/** Ein Browser mit eigener Keksdose. */
final class Besucher
{
    private string $kekse;
    public int $status = 0;
    public string $ort = '';
    public string $inhalt = '';

    public function __construct(private string $basis, string $ordner)
    {
        $this->kekse = $ordner . '/kekse-' . bin2hex(random_bytes(4));
    }

    public function holen(string $pfad, ?array $post = null, array $kopf = []): self
    {
        $c = curl_init($this->basis . $pfad);
        curl_setopt_array($c, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_COOKIEJAR => $this->kekse, CURLOPT_COOKIEFILE => $this->kekse, CURLOPT_TIMEOUT => 20,
            CURLOPT_HTTPHEADER => $kopf,
        ]);
        if ($post !== null) {
            curl_setopt($c, CURLOPT_POST, true);
            curl_setopt($c, CURLOPT_POSTFIELDS, http_build_query($post));
        }
        $antwort = (string) curl_exec($c);
        $this->status = (int) curl_getinfo($c, CURLINFO_RESPONSE_CODE);
        $groesse = (int) curl_getinfo($c, CURLINFO_HEADER_SIZE);
        $kopfzeilen = substr($antwort, 0, $groesse);
        $this->inhalt = substr($antwort, $groesse);
        $this->ort = preg_match('/^Location:\s*(.+)$/mi', $kopfzeilen, $m) ? trim($m[1]) : '';
        curl_close($c);
        return $this;
    }

    public function csrf(string $pfad): string
    {
        $this->holen($pfad);
        if (preg_match('/name="_csrf" value="([^"]+)"/', $this->inhalt, $m)) {
            return $m[1];
        }
        return preg_match('/window\.gpCsrf\s*=\s*"([^"]+)"/', $this->inhalt, $m) ? $m[1] : '';
    }

    public function anmelden(string $email, string $passwort): self
    {
        $t = $this->csrf('/login.php');
        return $this->holen('/login.php', ['_csrf' => $t, 'email' => $email, 'passwort' => $passwort]);
    }
}

$basis = 'http://127.0.0.1:' . $port;
try {

    abschnitt('Zugang zur Betreiberzentrale');
    $gast = new Besucher($basis, $ordner);
    $gast->holen('/master/instanzen.php');
    pruefe($gast->status === 302 && str_contains($gast->ort, '/login.php'), 'ohne Anmeldung: weiter zur Anmeldung');

    $anna = new Besucher($basis, $ordner);
    $anna->anmelden('anna@alpha.example', $pw);
    pruefe($anna->status === 302 && str_contains($anna->ort, '/app/'), 'Golfpro landet in seiner Instanz');
    foreach (['/master/', '/master/instanzen.php', '/master/instanz.php?id=' . $Bid, '/master/protokoll.php', '/master/system.php'] as $pfad) {
        $anna->holen($pfad);
        pruefe($anna->status === 403 && str_contains($anna->inhalt, 'Keine Berechtigung'), 'Golfpro: GET ' . $pfad . ' → 403');
    }
    $anna->holen('/master/suche.php?q=Beta');
    pruefe($anna->status === 403 && !str_contains($anna->inhalt, 'Instanz Beta'), 'Golfpro: Suche der Zentrale → 403, verrät nichts');
    $t = $anna->csrf('/app/');
    $anna->holen('/master/support.php', ['_csrf' => $t, 'aktion' => 'start', 'id' => $Bid]);
    pruefe($anna->status === 403, 'Golfpro: Support Mode für fremde Instanz starten → 403');
    $anna->holen('/master/instanz.php', ['_csrf' => $t, 'aktion' => 'status', 'id' => $Bid, 'neu' => 'gesperrt', 'erwartet' => 'test']);
    pruefe($anna->status === 403 && DB::value('SELECT status FROM workspaces WHERE id = :i', ['i' => $Bid]) === 'test',
        'Golfpro: fremde Instanz sperren (manipulierte ID) → 403, nichts geändert');
    pruefe(DB::int("SELECT COUNT(*) FROM betreiber_log WHERE aktion = 'ACCESS_DENIED'") >= 5, 'jeder Versuch steht im Audit-Log');

    abschnitt('Rollen lassen sich nicht nach oben schieben');
    $t = $anna->csrf('/app/team.php');
    $anna->holen('/app/team.php', ['_csrf' => $t, 'aktion' => 'speichern', 'id' => 0, 'name' => 'Eve', 'email' => 'eve@alpha.example',
        'rolle' => 'master_admin', 'aktiv' => '1']);
    pruefe((string) DB::value("SELECT rolle FROM users WHERE email = 'eve@alpha.example'") === 'trainer',
        'Rolle „master_admin" wird in einer Instanz zu „trainer"');
    pruefe(DB::int('SELECT COUNT(*) FROM betreiber') === 1, 'es entsteht kein Betreiber');
    $eve = new Besucher($basis, $ordner);
    DB::update('users', ['passwort' => password_hash($pw, PASSWORD_DEFAULT)], "email = 'eve@alpha.example'");
    $eve->anmelden('eve@alpha.example', $pw);
    $eve->holen('/master/');
    pruefe($eve->status === 403, 'auch dieser Zugang kommt nicht in die Zentrale');

    abschnitt('Mandantengrenze');
    $anna->holen('/app/kunde.php?id=' . $kundeB);
    pruefe($anna->status === 302 && !str_contains($anna->inhalt, 'Betakundin'), 'fremder Kunde über die ID: nicht gefunden, kein Inhalt');
    $anna->holen('/app/kunden.php?q=Betakundin');
    pruefe(!str_contains($anna->inhalt, 'geheim@beta-kunde.example'), 'Suche in der eigenen Instanz findet fremde Kunden nicht');
    Tenant::setzen($A);
    pruefe(Tenant::find('customers', $kundeB) === null, 'Tenant::find() liefert fremde Datensätze nicht');
    Tenant::setzen(0);

    abschnitt('Rechnungen in der Instanz');
    $bert = new Besucher($basis, $ordner);
    $bert->anmelden('bert@beta.example', $pw);
    $bert->holen('/app/tarif.php');
    pruefe(str_contains($bert->inhalt, (string) $rechnung1['nummer']) && str_contains($bert->inhalt, (string) $storno['nummer']),
        'Golfpro sieht seine ausgestellten Rechnungen');
    $bert->holen('/app/teepilot-rechnung.php?id=' . $r1);
    pruefe($bert->status === 200 && str_starts_with($bert->inhalt, '%PDF-'), 'und lädt das PDF');
    $bert->holen('/app/teepilot-rechnung.php?id=' . $entwurfB);
    pruefe($bert->status === 404, 'Entwürfe bleiben unsichtbar (404)');
    $anna->holen('/app/teepilot-rechnung.php?id=' . $r1);
    pruefe($anna->status === 404 && !str_starts_with($anna->inhalt, '%PDF'), 'fremde Instanz: 404, kein PDF');
    $anna->holen('/master/rechnung-pdf.php?id=' . $r1);
    pruefe($anna->status === 403, 'Golfpro: PDF über die Zentrale → 403');
    $anna->holen('/master/rechnungen.php');
    pruefe($anna->status === 403, 'Golfpro: Rechnungsliste der Zentrale → 403');

    abschnitt('Betreiber');
    $chef = new Besucher($basis, $ordner);
    $chef->anmelden('chef@betrieb.example', $pw);
    pruefe($chef->status === 302 && str_contains($chef->ort, '/master/'), 'Betreiber landet in der Betreiberzentrale');
    $chef->holen('/master/instanzen.php?status=alle');
    pruefe($chef->status === 200 && str_contains($chef->inhalt, 'Instanz Alpha') && str_contains($chef->inhalt, 'Golfschule Alt Eins'),
        'Liste zeigt neue und bestehende Instanzen');
    $chef->holen('/app/');
    pruefe($chef->status === 302, 'Betreiber hat ohne Support Mode keinen Zugang zu einer Instanz');
    foreach (['/master/', '/master/benutzer.php', '/master/instanz.php?id=' . $A . '&reiter=benutzer', '/master/system.php',
              '/master/einstellungen.php', '/master/protokoll.php'] as $pfad) {
        $chef->holen($pfad);
        $leck = preg_match('/\$2y\$|sk_test_GEHEIMNIS|' . preg_quote($geheim, '/') . '/', $chef->inhalt) === 1;
        pruefe($chef->status === 200 && !$leck, $pfad . ': keine Passwort-Hashes, keine Schlüssel');
    }
    $t = $chef->csrf('/master/');
    $chef->holen('/master/instanz.php', ['aktion' => 'status', 'id' => $A, 'neu' => 'pausiert', 'erwartet' => 'aktiv']);
    pruefe($chef->status === 400, 'Handlung ohne CSRF-Merkmal → 400');

    abschnitt('Instanz bearbeiten, Paket ändern');
    $chef->holen('/master/instanz.php', ['_csrf' => $t, 'aktion' => 'bearbeiten', 'id' => $A, 'name' => 'Instanz Alpha Neu',
        'typ' => 'pro', 'waehrung' => 'EUR', 'zeitzone' => 'Europe/Berlin', 'slug' => 'alpha', 'domain' => '']);
    pruefe(DB::value('SELECT name FROM workspaces WHERE id = :i', ['i' => $A]) === 'Instanz Alpha Neu', 'Name geändert');
    $log = DB::one("SELECT * FROM betreiber_log WHERE aktion = 'TENANT_UPDATED' ORDER BY id DESC LIMIT 1");
    pruefe($log !== null && str_contains((string) $log['vorher'], 'Instanz Alpha') && str_contains((string) $log['nachher'], 'Neu'),
        'Audit-Log: TENANT_UPDATED mit vorher und nachher');

    $anna->holen('/app/videos.php');
    pruefe($anna->status === 403 && str_contains($anna->inhalt, 'nicht zum Paket'), 'Starter: Videoanalyse über die Adresszeile → 403');
    $t2 = $anna->csrf('/app/tarif.php');
    $anna->holen('/app/tarif.php', ['_csrf' => $t2, 'aktion' => 'plan', 'plan' => 'academy']);
    pruefe(DB::value('SELECT plan FROM workspaces WHERE id = :i', ['i' => $A]) === 'starter',
        'Inhaber kann ein von TeePilot verwaltetes Paket nicht selbst hochstufen');

    $chef->holen('/master/instanz.php', ['_csrf' => $t, 'aktion' => 'paket', 'id' => $A, 'paket' => 'academy', 'grund' => 'Upgrade']);
    pruefe(DB::value('SELECT plan FROM workspaces WHERE id = :i', ['i' => $A]) === 'academy', 'Betreiber ändert das Paket');
    pruefe(DB::int("SELECT COUNT(*) FROM betreiber_log WHERE aktion = 'PLAN_CHANGED' AND instanz_id = :i AND grund = 'Upgrade'", ['i' => $A]) === 1,
        'Audit-Log: PLAN_CHANGED mit Grund');
    Tenant::setzen($A);
    Tenant::einstellungSetzen('module', array_merge(Module::KERN, ['video']));
    Tenant::setzen(0);
    $anna->holen('/app/videos.php');
    pruefe($anna->status === 200, 'nach dem Upgrade und Einschalten: Videoanalyse erreichbar');
    $chef->holen('/master/instanz.php', ['_csrf' => $t, 'aktion' => 'paket', 'id' => $A, 'paket' => 'starter', 'grund' => 'Downgrade']);
    $anna->holen('/app/videos.php');
    pruefe($anna->status === 403, 'nach dem Downgrade sofort wieder gesperrt');
    Tenant::setzen($A);
    pruefe(!in_array('video', (array) Tenant::einstellung('module', []), true), 'Downgrade schaltet das Modul auch in der Liste ab');
    Tenant::setzen(0);

    abschnitt('Status: pausieren, sperren, aktivieren');
    $chef->holen('/master/instanz.php', ['_csrf' => $t, 'aktion' => 'status', 'id' => $A, 'neu' => 'pausiert', 'erwartet' => 'aktiv', 'grund' => 'Zahlung offen']);
    pruefe(DB::value('SELECT status FROM workspaces WHERE id = :i', ['i' => $A]) === 'pausiert', 'pausiert');
    $anna->holen('/app/');
    pruefe($anna->status === 302, 'laufende Sitzung des Golfpros endet mit dem nächsten Klick');
    $anna->anmelden('anna@alpha.example', $pw);
    pruefe($anna->status === 200 && str_contains($anna->inhalt, 'pausiert'), 'Anmeldung erklärt: pausiert');
        $web = new Besucher($basis, $ordner);
    $web->holen('/buchen.php?w=alpha');
    pruefe($web->status === 200, 'pausiert: Website und Online-Buchung bleiben erreichbar');
    $chef->holen('/master/instanz.php', ['_csrf' => $t, 'aktion' => 'status', 'id' => $A, 'neu' => 'gesperrt', 'erwartet' => 'aktiv', 'grund' => 'x']);
    pruefe(DB::value('SELECT status FROM workspaces WHERE id = :i', ['i' => $A]) === 'pausiert',
        'veralteter Stand (erwartet „aktiv") ändert nichts – kein stilles Überschreiben');
    $chef->holen('/master/instanz.php', ['_csrf' => $t, 'aktion' => 'status', 'id' => $A, 'neu' => 'gesperrt', 'erwartet' => 'pausiert', 'grund' => 'Missbrauch']);
    $web->holen('/buchen.php?w=alpha');
    pruefe($web->status === 404, 'gesperrt: Website offline');
    $chef->holen('/master/instanz.php', ['_csrf' => $t, 'aktion' => 'status', 'id' => $A, 'neu' => 'aktiv', 'erwartet' => 'gesperrt']);
    $web->holen('/buchen.php?w=alpha');
    $anna->anmelden('anna@alpha.example', $pw);
    pruefe($web->status === 200 && $anna->status === 302, 'aktiviert: Website und Anmeldung wieder da');
    foreach (['TENANT_PAUSED', 'TENANT_LOCKED', 'TENANT_ACTIVATED'] as $aktion) {
        pruefe(DB::int('SELECT COUNT(*) FROM betreiber_log WHERE aktion = :a AND instanz_id = :i', ['a' => $aktion, 'i' => $A]) >= 1,
            'Audit-Log: ' . $aktion);
    }

    abschnitt('Support Mode');
    $letzterLogin = (string) DB::value('SELECT letzter_login FROM users WHERE id = :i', ['i' => $annaId]);
    $chef->holen('/master/support.php', ['_csrf' => $t, 'aktion' => 'start', 'id' => $A, 'grund' => 'Ticket 7']);
    pruefe($chef->status === 302 && str_contains($chef->ort, '/app/'), 'Support Mode startet in der Instanz');
    $chef->holen('/app/kunden.php');
    pruefe($chef->status === 200 && str_contains($chef->inhalt, 'support-leiste')
        && str_contains($chef->inhalt, 'Du greifst gerade als TeePilot Master Admin auf diese Instanz zu.'), 'Banner auf der Seite der Instanz');
    pruefe((string) DB::value('SELECT letzter_login FROM users WHERE id = :i', ['i' => $annaId]) === $letzterLogin,
        '„Letzte Anmeldung" des Inhabers bleibt unberührt');
    $ts = $chef->csrf('/app/standorte.php');
    $chef->holen('/app/standorte.php', ['_csrf' => $ts, 'aktion' => 'speichern', 'id' => 0, 'name' => 'Support-Standort']);
    $zeile = DB::one("SELECT * FROM audit_log WHERE workspace_id = :w AND aktion = 'erstellt' AND objekt = 'location' ORDER BY id DESC LIMIT 1", ['w' => $A]);
    pruefe($zeile !== null && (int) $zeile['betreiber_id'] === $betreiberId, 'Änderung im Support Mode ist im Protokoll der Instanz als Support markiert');
    $chef->holen('/app/profil.php', ['_csrf' => $ts, 'aktion' => 'profil', 'name' => 'x', 'email' => 'boese@example.org']);
    pruefe(DB::value('SELECT email FROM users WHERE id = :i', ['i' => $annaId]) === 'anna@alpha.example', 'E-Mail des Inhabers lässt sich im Support Mode nicht ändern');
    $chef->holen('/app/team.php', ['_csrf' => $ts, 'aktion' => 'speichern', 'id' => 0, 'name' => 'Hintertür', 'email' => 'hinter@tuer.example', 'rolle' => 'owner', 'aktiv' => '1']);
    pruefe(DB::int("SELECT COUNT(*) FROM users WHERE email = 'hinter@tuer.example'") === 0, 'im Support Mode entstehen keine neuen Zugänge');
    $chef->holen('/master/instanzen.php');
    pruefe($chef->status === 200 && str_contains($chef->inhalt, 'Support-Sitzung'), 'Zentrale bleibt erreichbar und zeigt die laufende Sitzung');
    $chef->holen('/master/support.php', ['_csrf' => $ts, 'aktion' => 'ende']);
    pruefe($chef->status === 302 && str_contains($chef->ort, '/master/instanz.php?id=' . $A), 'Beenden führt zurück zur Instanz');
    $chef->holen('/app/');
    pruefe($chef->status === 302, 'danach kein Zugriff mehr auf die Instanz');
    pruefe(DB::int("SELECT COUNT(*) FROM betreiber_log WHERE aktion IN ('SUPPORT_SESSION_STARTED', 'SUPPORT_SESSION_ENDED') AND instanz_id = :i", ['i' => $A]) === 2,
        'Audit-Log: Beginn und Ende');
    pruefe(DB::int("SELECT COUNT(*) FROM audit_log WHERE workspace_id = :w AND aktion IN ('support_beginn', 'support_ende')", ['w' => $A]) === 2,
        'die Instanz sieht in ihrem Protokoll, dass der Support da war');
    $anna->holen('/app/protokoll.php');
    pruefe(str_contains($anna->inhalt, 'TeePilot Support'), 'Inhaber sieht „TeePilot Support" als Person im Protokoll');

    abschnitt('Archivieren und löschen');
    [$rA] = Betreiberrechnungen::entwurfSpeichern(0, $A, ['empfaenger' => ['firma' => 'Alpha', 'strasse' => 'A-Weg 1',
        'plz' => '12345', 'ort' => 'Alphastadt', 'land' => 'DE']], $pos);
    Betreiberrechnungen::ausstellen($rA);
    Tenant::setzen($A);
    DB::insert('customers', ['workspace_id' => $A, 'vorname' => 'Weg', 'nachname' => 'Kunde', 'email' => 'weg@alpha.example', 'status' => 'aktiv', 'erstellt' => $jetzt]);
    Tenant::setzen(0);
    @mkdir(GP_ROOT . '/uploads/w' . $A . '/medien', 0755, true);
    file_put_contents(GP_ROOT . '/uploads/w' . $A . '/medien/test.txt', 'x');
    $zeilenB = DB::int('SELECT COUNT(*) FROM customers WHERE workspace_id = :w', ['w' => $Bid]);
    $chef->holen('/master/instanz.php', ['_csrf' => $t, 'aktion' => 'loeschen', 'id' => $A, 'bestaetigung' => 'Instanz Alpha Neu', 'grund' => 'x']);
    pruefe(DB::int('SELECT COUNT(*) FROM workspaces WHERE id = :i', ['i' => $A]) === 1, 'nicht archiviert: Löschen verweigert');
    $chef->holen('/master/instanz.php', ['_csrf' => $t, 'aktion' => 'status', 'id' => $A, 'neu' => 'archiviert', 'erwartet' => 'aktiv', 'grund' => 'Kündigung']);
    $chef->holen('/master/instanz.php', ['_csrf' => $t, 'aktion' => 'loeschen', 'id' => $A, 'bestaetigung' => 'Instanz Alpha', 'grund' => 'x']);
    pruefe(DB::int('SELECT COUNT(*) FROM workspaces WHERE id = :i', ['i' => $A]) === 1, 'falscher Name: Löschen verweigert');
    $chef->holen('/master/instanz.php', ['_csrf' => $t, 'aktion' => 'loeschen', 'id' => $A, 'bestaetigung' => 'Instanz Alpha Neu', 'grund' => 'DSGVO']);
    $rest = 0;
    foreach (Instanzen::tabellenMitWorkspace() as $tabelle) {
        $rest += DB::int('SELECT COUNT(*) FROM ' . $tabelle . ' WHERE workspace_id = :w', ['w' => $A]);
    }
    pruefe(DB::int('SELECT COUNT(*) FROM workspaces WHERE id = :i', ['i' => $A]) === 0 && $rest === 0, 'gelöscht: keine Zeile mit dieser Instanz mehr');
    pruefe(!is_dir(GP_ROOT . '/uploads/w' . $A), 'hochgeladene Dateien entfernt');
    pruefe(DB::int('SELECT COUNT(*) FROM customers WHERE workspace_id = :w', ['w' => $Bid]) === $zeilenB, 'die andere Instanz ist unberührt');
    pruefe(DB::int("SELECT COUNT(*) FROM betreiber_log WHERE aktion = 'TENANT_DELETED' AND instanz_id = :i", ['i' => $A]) === 1,
        'Audit-Log: TENANT_DELETED bleibt stehen');
    $rechnungA = Betreiberrechnungen::finden($rA);
    pruefe($rechnungA !== null && Betreiberrechnungen::dateiInhalt($rechnungA) !== null,
        'Rechnungen an die gelöschte Instanz bleiben samt PDF erhalten (Aufbewahrungspflicht)');
    $pfad = Betreiberrechnungen::ablage() . '/' . $rechnungA['datei'];
    file_put_contents($pfad, file_get_contents($pfad) . ' ');
    $chef->holen('/master/rechnung-pdf.php?id=' . $rA);
    pruefe($chef->status === 409, 'veränderte Ablagedatei wird erkannt und nicht ausgeliefert');

    abschnitt('Export');
    $chef->holen('/master/instanzen.php', ['_csrf' => $t, 'aktion' => 'export', 'status' => 'alle']);
    pruefe($chef->status === 302, 'Export ohne Bestätigung: verweigert');
    $chef->holen('/master/instanzen.php', ['_csrf' => $t, 'aktion' => 'export', 'status' => 'alle', 'bestaetigt' => '1']);
    pruefe($chef->status === 200 && str_contains($chef->inhalt, 'Instanz Beta') && !is_dir(GP_ROOT . '/data/export'),
        'Export geliefert, ohne Datei auf dem Server');
    pruefe(DB::int("SELECT COUNT(*) FROM betreiber_log WHERE aktion = 'EXPORT_CREATED'") === 1, 'Audit-Log: EXPORT_CREATED');

} finally {
    /* Bricht der Test vor dem Löschen ab, bleibt sonst die Testdatei in
       uploads/ liegen. Die ID ist eine der hohen Test-IDs, nie eine echte. */
    foreach ([GP_ROOT . '/uploads/w' . $A . '/medien/test.txt', GP_ROOT . '/uploads/w' . $A . '/medien', GP_ROOT . '/uploads/w' . $A] as $rest) {
        is_dir($rest) ? @rmdir($rest) : @unlink($rest);
    }
    proc_terminate($server);
    proc_close($server);
    if (is_dir($ordner)) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($ordner, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($ordner);
    }
}

fwrite(STDOUT, PHP_EOL . $ergebnisse['ok'] . ' bestanden, ' . $ergebnisse['fehl'] . ' fehlgeschlagen.' . PHP_EOL);
exit($ergebnisse['fehl'] === 0 ? 0 : 1);
