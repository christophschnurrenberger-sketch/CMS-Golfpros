<?php
/**
 * App – der Rahmen: Adressen, Eingaben, Meldungen, Weiterleitungen.
 *
 * Die Basisadresse wird aus dem Dateipfad errechnet statt konfiguriert.
 * Damit läuft dieselbe Installation im Wurzelverzeichnis, in /golfpro und
 * auf einer Subdomain, ohne dass jemand etwas umstellt – der häufigste
 * Fehler beim Hochladen fällt so ganz weg.
 */
final class App
{
    private static ?string $basis = null;
    private static ?array $hosts = null;
    private static array $meldungen = [];

    public static function basis(): string
    {
        if (self::$basis !== null) {
            return self::$basis;
        }
        $konfiguriert = trim((string) Config::get('base_url', ''));
        if ($konfiguriert !== '') {
            return self::$basis = rtrim($konfiguriert, '/');
        }
        $skript = (string) ($_SERVER['SCRIPT_NAME'] ?? '');
        $datei  = (string) ($_SERVER['SCRIPT_FILENAME'] ?? '');
        $datei  = str_replace('\\', '/', (string) (realpath($datei) ?: $datei));
        $wurzel = str_replace('\\', '/', GP_ROOT);

        if ($datei !== '' && str_starts_with($datei, $wurzel)) {
            $relativ = substr($datei, strlen($wurzel));              // /app/kunden.php
            if ($relativ !== '' && str_ends_with($skript, $relativ)) {
                return self::$basis = rtrim(substr($skript, 0, strlen($skript) - strlen($relativ)), '/');
            }
        }
        return self::$basis = rtrim(dirname($skript), '/');
    }

    public static function url(string $pfad = '/'): string
    {
        if (str_starts_with($pfad, 'http://') || str_starts_with($pfad, 'https://')) {
            return $pfad;
        }
        return self::basis() . '/' . ltrim($pfad, '/');
    }

    /** Statische Datei mit Versionsstempel – erspart das Leeren des Browsercaches. */
    public static function asset(string $pfad): string
    {
        $voll = GP_ROOT . '/' . ltrim($pfad, '/');
        $stempel = is_file($voll) ? (string) filemtime($voll) : (string) time();
        return self::url($pfad) . '?v=' . substr(md5($stempel), 0, 8);
    }

    /**
     * Vollständige Adresse mit Schema und Host – für E-Mails, Sitemap und
     * alles andere, was den Server verlässt.
     *
     * Zwei Fallen stecken hier drin, und beide sind teuer gewesen:
     *
     * **Doppelte Adresse.** Steht in der config.php eine vollständige
     * `base_url` – und der Installer schreibt immer eine –, dann liefert
     * `url()` bereits eine absolute Adresse. Wer dann noch einmal Schema
     * und Host davorsetzt, erzeugt
     * `https://beispiel.dehttps://beispiel.de/portal/`. Das ist keine
     * gültige Adresse, und genau so sah bis hierher jeder Link in jeder
     * E-Mail aus: Zugangslink, Passwort-zurücksetzen und der
     * Newsletter-Abmeldelink, den § 7 UWG verlangt.
     *
     * **Gefälschte Host-Kopfzeile.** Fehlt die `base_url`, bleibt nur
     * `HTTP_HOST` – ein Wert, den der Anfragende frei bestimmt. Wer für
     * eine fremde Adresse ein Passwort zurücksetzen lässt und dabei
     * `Host: boese.example` mitschickt, bekommt den Klick des Opfers samt
     * gültigem Token auf den eigenen Server. Deshalb wird der Host gegen
     * die Namen geprüft, die zu dieser Anlage gehören.
     */
    public static function absolut(string $pfad = '/'): string
    {
        $url = self::url($pfad);
        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
            return $url;
        }

        $schema = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
               || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https' ? 'https' : 'http';

        return $schema . '://' . self::host() . $url;
    }

    /**
     * Der Hostname, unter dem diese Anlage erreichbar ist.
     *
     * Zulässig ist, was in der `base_url` steht und was als eigene Domain
     * eines Workspace eingetragen ist – alles andere wird verworfen und
     * durch den ersten zulässigen Namen ersetzt. Ein Link in einer E-Mail
     * zeigt damit nie irgendwo hin, nur weil jemand eine Kopfzeile
     * gefälscht hat.
     */
    public static function host(): string
    {
        $gemeldet = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
        $erlaubt  = self::erlaubteHosts();

        if ($erlaubt !== []) {
            return in_array($gemeldet, $erlaubt, true) ? $gemeldet : $erlaubt[0];
        }

        /*
         * Nichts konfiguriert und keine Domain hinterlegt – der Installer
         * schreibt zwar immer eine base_url, aber jemand kann sie geleert
         * haben. Dann zählt SERVER_NAME vor HTTP_HOST: Der erste Wert
         * stammt aus der Serverkonfiguration, der zweite aus der Anfrage.
         * Nur wenn es auch den nicht gibt, bleibt der gemeldete Name –
         * bereinigt, damit über ihn nichts in die Kopfzeile gerät.
         */
        $server = strtolower(trim((string) ($_SERVER['SERVER_NAME'] ?? '')));
        $name   = $server !== '' ? $server : $gemeldet;

        return preg_replace('/[^a-z0-9.\-:]/', '', $name) ?: 'localhost';
    }

    /** @return string[] kleingeschrieben, ohne Schema, mit Port falls angegeben */
    private static function erlaubteHosts(): array
    {
        if (self::$hosts !== null) {
            return self::$hosts;
        }
        $liste = [];

        $basis = trim((string) Config::get('base_url', ''));
        if ($basis !== '') {
            $teil = parse_url($basis, PHP_URL_HOST);
            $port = parse_url($basis, PHP_URL_PORT);
            if (is_string($teil) && $teil !== '') {
                $liste[] = strtolower($teil) . ($port ? ':' . $port : '');
            }
        }
        foreach ((array) Config::get('erlaubte_hosts', []) as $eintrag) {
            $eintrag = strtolower(trim((string) $eintrag));
            if ($eintrag !== '') {
                $liste[] = $eintrag;
            }
        }

        /*
         * Die eigenen Domains der Workspaces zählen mit – sonst trüge eine
         * Mail an den Kunden einer Golfschule mit eigener Domain den
         * Hostnamen der Anlage statt ihren eigenen.
         */
        try {
            if (Config::installed()) {
                foreach (DB::all("SELECT domain FROM workspaces WHERE domain != ''") as $z) {
                    $d = strtolower(trim((string) $z['domain']));
                    if ($d !== '') {
                        $liste[] = $d;
                        $liste[] = 'www.' . $d;
                    }
                }
            }
        } catch (Throwable $e) {
            // Vor der Einrichtung gibt es die Tabelle noch nicht.
        }

        return self::$hosts = array_values(array_unique($liste));
    }

    public static function weiter(string $pfad): never
    {
        header('Location: ' . self::url($pfad));
        exit;
    }

    /**
     * Zurück, woher der Klick kam – aber nur innerhalb dieser Anlage.
     *
     * Woher er kam, sagt der Browser über die Referer-Kopfzeile, und die
     * setzt letztlich die Seite, die den Klick ausgelöst hat. Ungeprüft
     * übernommen wird daraus eine Weiterleitung auf jede beliebige
     * Adresse: Ein fremdes Formular schickt an diese Anwendung, die
     * schickt den Angemeldeten weiter. Deshalb wird er nur übernommen,
     * wenn er auf diese Installation zeigt; sonst geht es auf die
     * Startseite der Anwendung.
     */
    public static function zurueck(): never
    {
        $referer = (string) ($_SERVER['HTTP_REFERER'] ?? '');
        $eigen   = self::absolut('/');
        $ziel    = $referer !== '' && str_starts_with($referer, $eigen)
                 ? $referer
                 : self::url('/app/');
        header('Location: ' . $ziel);
        exit;
    }

    /* ------------------------------------------------------- Eingaben --- */

    public static function post(string $name, string $standard = ''): string
    {
        $wert = $_POST[$name] ?? $standard;
        return is_string($wert) ? trim($wert) : $standard;
    }

    public static function postRoh(string $name, string $standard = ''): string
    {
        $wert = $_POST[$name] ?? $standard;
        return is_string($wert) ? $wert : $standard;
    }

    public static function postInt(string $name, int $standard = 0): int
    {
        return isset($_POST[$name]) ? (int) $_POST[$name] : $standard;
    }

    public static function postBool(string $name): bool
    {
        return !empty($_POST[$name]);
    }

    /** @return string[] */
    public static function postListe(string $name): array
    {
        $wert = $_POST[$name] ?? [];
        return is_array($wert) ? array_values(array_map('strval', $wert)) : [];
    }

    public static function get(string $name, string $standard = ''): string
    {
        $wert = $_GET[$name] ?? $standard;
        return is_string($wert) ? trim($wert) : $standard;
    }

    public static function getInt(string $name, int $standard = 0): int
    {
        return isset($_GET[$name]) ? (int) $_GET[$name] : $standard;
    }

    public static function istPost(): bool
    {
        return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
    }

    /**
     * Wurde der POST verworfen, weil er groesser war als post_max_size?
     *
     * PHP wirft in diesem Fall den ganzen Inhalt weg: $_POST und $_FILES
     * sind leer, obwohl der Browser etwas geschickt hat. Ohne diese Abfrage
     * laeuft die Anwendung in die CSRF-Pruefung und meldet
     * „Sicherheitspruefung fehlgeschlagen" - eine Auskunft, die in die
     * falsche Richtung schickt. Der Fehler ist immer derselbe: ein zu
     * grosses Foto.
     */
    public static function postVerworfen(): bool
    {
        return self::istPost()
            && $_POST === [] && $_FILES === []
            && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0;
    }

    /** Die Obergrenze fuer einen Upload, wie PHP sie tatsaechlich sieht. */
    public static function uploadGrenze(): int
    {
        $werte = array_filter([
            self::iniBytes('post_max_size'),
            self::iniBytes('upload_max_filesize'),
        ]);
        return $werte === [] ? 0 : (int) min($werte);
    }

    /** „12M" und „1G" sind fuer PHP Zahlen, fuer PHP-Code aber nicht. */
    private static function iniBytes(string $name): int
    {
        $roh = trim((string) ini_get($name));
        if ($roh === '') {
            return 0;
        }
        $zahl = (int) $roh;
        return match (strtolower(substr($roh, -1))) {
            'g' => $zahl * 1024 * 1024 * 1024,
            'm' => $zahl * 1024 * 1024,
            'k' => $zahl * 1024,
            default => $zahl,
        };
    }

    public static function aktion(): string
    {
        return self::post('aktion', self::get('aktion'));
    }

    /* ------------------------------------------------------- Meldungen --- */

    public static function melden(string $text, string $typ = 'erfolg'): void
    {
        Auth::start();
        $_SESSION['gp_meldungen'][] = ['typ' => $typ, 'text' => $text];
    }

    /** @return array<int,array{typ:string,text:string}> */
    public static function meldungen(): array
    {
        Auth::start();
        $liste = $_SESSION['gp_meldungen'] ?? [];
        unset($_SESSION['gp_meldungen']);
        return is_array($liste) ? $liste : [];
    }

    /* --------------------------------------------------------- Antwort --- */

    public static function json($daten, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo Util::json($daten);
        exit;
    }

    /** Welcher Bereich ist gerade offen? Bestimmt die Markierung im Menü. */
    public static function bereich(): string
    {
        $datei = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''), '.php');
        $karte = [
            'index' => 'dashboard', 'kunde' => 'customers', 'kunden' => 'customers',
            'lead' => 'leads', 'kalender' => 'calendar', 'buchungen' => 'bookings',
            'buchung' => 'bookings', 'leistungen' => 'bookings', 'pakete' => 'packages',
            'verfuegbarkeit' => 'bookings',
            'training' => 'training', 'trainingsplan' => 'training', 'uebungen' => 'training',
            'leistungsdaten' => 'training',
            'videoanalyse' => 'video', 'videos' => 'video',
            'kurs' => 'courses', 'kurse' => 'courses',
            'produkte' => 'products', 'produkt' => 'products', 'gutscheine' => 'products',
            'zahlungen' => 'payments', 'rechnungen' => 'invoices', 'rechnung' => 'invoices',
            'marketing' => 'marketing', 'newsletter' => 'newsletter', 'kampagne' => 'newsletter',
            'automationen' => 'automations', 'automation' => 'automations',
            'website' => 'website', 'baukasten' => 'website', 'seiten' => 'website',
            'design' => 'website', 'seo' => 'website',
            'inhalte' => 'content', 'beitrag' => 'content', 'medien' => 'content',
            'events' => 'events', 'event' => 'events',
            'community' => 'community', 'auswertung' => 'analytics', 'ki' => 'ai',
            'einstellungen' => 'settings', 'team' => 'settings', 'standorte' => 'settings',
            'protokoll' => 'settings', 'tarif' => 'settings', 'datenschutz' => 'settings',
            'profil' => 'settings', 'onboarding' => 'dashboard',
            'segmente' => 'customers', 'aufgaben' => 'dashboard',
            'benachrichtigungen' => 'dashboard', 'suche' => 'dashboard',
        ];
        return $karte[$datei] ?? $datei;
    }

    /** Sauberer 403-Hinweis statt einer weißen Seite. */
    public static function verweigert(string $recht = ''): void
    {
        $titel = 'Kein Zugriff';
        $text  = 'Deine Rolle „' . Util::h(Auth::rollenName()) . '“ hat für diesen Bereich keine Berechtigung. '
               . 'Wenn du sie brauchst, kann der Inhaber sie unter Einstellungen → Team freischalten.';
        require GP_ROOT . '/app/partials/kopf.php';
        echo '<div class="leerzustand leerzustand--gross">'
           . '<div class="leerzustand__symbol">' . Icon::svg('lock', 30) . '</div>'
           . '<h2>' . $titel . '</h2><p>' . $text . '</p>'
           . ($recht !== '' ? '<p class="mono klein gedimmt">Benötigt: ' . Util::h($recht) . '</p>' : '')
           . '<a class="btn btn--primaer" href="' . self::url('/app/') . '">Zum Dashboard</a>'
           . '</div>';
        require GP_ROOT . '/app/partials/fuss.php';
    }

    /** Seitenzahlen für lange Listen. */
    public static function seitenNavigation(int $gesamt, int $proSeite, int $seite, string $basisUrl): string
    {
        $seiten = (int) ceil($gesamt / max(1, $proSeite));
        if ($seiten <= 1) {
            return '';
        }
        $html = '<nav class="seitennav" aria-label="Seiten">';
        $html .= '<span class="seitennav__info">' . Util::zahl($gesamt) . ' Einträge</span>';
        $trenner = str_contains($basisUrl, '?') ? '&' : '?';
        $zeige = function (int $n) use ($seite, $basisUrl, $trenner): string {
            $aktiv = $n === $seite ? ' ist-aktiv' : '';
            return '<a class="seitennav__zahl' . $aktiv . '" href="' . Util::attr($basisUrl . $trenner . 'seite=' . $n) . '">' . $n . '</a>';
        };
        for ($n = 1; $n <= $seiten; $n++) {
            if ($n <= 2 || $n > $seiten - 2 || abs($n - $seite) <= 1) {
                $html .= $zeige($n);
            } elseif ($n === 3 && $seite > 4) {
                $html .= '<span class="seitennav__aus">…</span>';
            } elseif ($n === $seiten - 2 && $seite < $seiten - 3) {
                $html .= '<span class="seitennav__aus">…</span>';
            }
        }
        return $html . '</nav>';
    }
}
