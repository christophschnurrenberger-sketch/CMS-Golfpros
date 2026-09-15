<?php
/**
 * Gemeinsame Grundlage der öffentlichen Endpunkte.
 *
 * buchen.php, kaufen.php und anfrage.php sind eigene Dateien, weil sie
 * verschiedene Dinge tun – aber alle drei müssen zuerst dieselbe Frage
 * beantworten: Zu welchem Workspace gehört dieser Aufruf? Und alle drei
 * sollen aussehen wie die Website, von der der Besucher kommt. Beides
 * steht hier, damit es nicht dreimal danebengehen kann.
 */
final class Oeffentlich
{
    /**
     * Bestimmt den Mandanten wie site.php: eigene Domain, dann ?w=slug,
     * dann – bei nur einer Installation – der einzige Workspace.
     * Ohne Treffer endet die Anfrage hier mit einem 404.
     *
     * Mit `$pflicht = false` gibt die Methode stattdessen null zurück.
     * Das Portal braucht das: Dort darf offenbleiben, zu welchem Betrieb
     * der Besucher gehört – die Anmeldung beantwortet es gleich selbst.
     */
    public static function mandantSetzen(bool $pflicht = true): ?array
    {
        $host = strtolower(preg_replace('/^www\./', '', (string) ($_SERVER['HTTP_HOST'] ?? '')) ?? '');
        $workspace = Tenant::nachDomain($host);

        if (!$workspace) {
            $slug = App::get('w') !== '' ? App::get('w') : (string) ($_POST['w'] ?? '');
            $workspace = $slug !== '' ? Tenant::nachSlug($slug) : null;
        }
        if (!$workspace && DB::int('SELECT COUNT(*) FROM workspaces WHERE aktiv = 1') === 1) {
            $workspace = Tenant::erster();
        }
        if (!$workspace) {
            if (!$pflicht) {
                return null;
            }
            http_response_code(404);
            exit('Diese Adresse gehört zu keiner Website.');
        }
        Tenant::setzen((int) $workspace['id']);
        return $workspace;
    }

    /** Adresse zurück auf die Website, mit erhaltenem Mandanten. */
    public static function url(string $pfad, array $parameter = []): string
    {
        $slug = (string) (Tenant::workspace()['slug'] ?? '');
        if ((string) (Tenant::workspace()['domain'] ?? '') === '') {
            $parameter = ['w' => $slug] + $parameter;
        }
        return App::url($pfad) . ($parameter !== [] ? '?' . http_build_query($parameter) : '');
    }

    /**
     * Seite im Gewand der Website. Nutzt denselben Kopf und Fuß wie der
     * Renderer – ein Buchungsschritt, der plötzlich anders aussieht als
     * die Seite davor, kostet Vertrauen und damit Buchungen.
     */
    public static function seite(string $titel, string $inhalt, array $o = []): never
    {
        $pseudoSeite = [
            'id' => 0, 'titel' => $titel, 'slug' => (string) ($o['slug'] ?? ''), 'startseite' => 0,
            'seo' => Util::json(['titel' => $titel, 'beschreibung' => (string) ($o['beschreibung'] ?? ''),
                                 'bild' => '', 'index' => false]),
        ];
        echo Website::ausgeben($pseudoSeite, $inhalt);
        exit;
    }

    /** Ein Schrittzähler über dem Inhalt – der Besucher soll wissen, wo er ist. */
    public static function schritte(array $namen, int $aktiv): string
    {
        $html = '<ol class="ablauf">';
        foreach ($namen as $i => $name) {
            $n = $i + 1;
            $klasse = $n === $aktiv ? ' ist-aktiv' : ($n < $aktiv ? ' ist-fertig' : '');
            $html .= '<li class="ablauf__teil' . $klasse . '"><span class="ablauf__zahl">' . $n . '</span>'
                   . '<span class="ablauf__name">' . Util::h($name) . '</span></li>';
        }
        return $html . '</ol>';
    }

    /** Kasten mit Überschrift – das Grundgerüst aller drei Endpunkte. */
    public static function kasten(string $titel, string $unter, string $inhalt): string
    {
        return '<div class="inhalt-breite inhalt-breite--schmal"><div class="vorgang">'
             . '<h1 class="vorgang__titel">' . Util::h($titel) . '</h1>'
             . ($unter !== '' ? '<p class="vorgang__unter">' . Util::h($unter) . '</p>' : '')
             . $inhalt . '</div></div>';
    }

    /** Meldung in der Farbe des Anlasses. */
    public static function meldung(string $text, string $art = 'warnung'): string
    {
        return '<div class="vorgang__meldung vorgang__meldung--' . Util::attr($art) . '">'
             . Util::h($text) . '</div>';
    }

    /**
     * Der Anmeldekasten über dem Buchungsformular.
     *
     * Bewusst zugeklappt: Die große Mehrheit bucht als Gast, und ein
     * Anmeldeformular, das ihnen zuerst ins Auge springt, wirkt wie eine
     * Pflicht. Wer ein Konto hat, sucht den Hinweis von selbst.
     *
     * Ohne JavaScript steht er offen da – ein `details`-Element braucht
     * keines. Das ist keine Notlösung, sondern hier sogar die robustere
     * Fassung.
     */
    public static function anmeldekasten(
        string $slug,
        int $serviceId,
        string $start,
        int $trainerId,
        string $fehler = ''
    ): string {
        $verstecktes = Auth::csrfFeld()
                     . '<input type="hidden" name="aktion" value="kunde_anmelden">'
                     . '<input type="hidden" name="w" value="' . Util::attr($slug) . '">'
                     . '<input type="hidden" name="service_id" value="' . $serviceId . '">'
                     . '<input type="hidden" name="start" value="' . Util::attr($start) . '">'
                     . '<input type="hidden" name="trainer_id" value="' . $trainerId . '">';

        return '<details class="anmeldekasten"' . ($fehler !== '' ? ' open' : '') . '>'
             . '<summary class="anmeldekasten__kopf">'
             . '<span>Schon Kunde? <strong>Anmelden</strong></span>'
             . '<span class="anmeldekasten__hinweis">spart das Ausfüllen</span>'
             . '</summary>'
             . '<div class="anmeldekasten__koerper">'
             . ($fehler !== '' ? '<p class="anmeldekasten__fehler">' . Util::h($fehler) . '</p>' : '')
             . '<form method="post" class="anmeldekasten__form">'
             . $verstecktes
             . '<div class="feld-paar">'
             . '<div class="feld"><label for="a-email">E-Mail</label>'
             . '<input id="a-email" type="email" name="email" autocomplete="email" required></div>'
             . '<div class="feld"><label for="a-passwort">Passwort</label>'
             . '<input id="a-passwort" type="password" name="passwort" autocomplete="current-password" required></div>'
             . '</div>'
             . '<button class="knopf" type="submit">Anmelden</button>'
             . '</form>'
             . '<p class="anmeldekasten__klein">Kein Passwort gesetzt? Der Link aus deiner '
             . 'letzten Terminbestätigung meldet dich ohne Passwort an. '
             . 'Sonst einfach unten als Gast buchen – das geht genauso.'
             /*
              * Der Weg zum Konto steht hier bewusst am Ende und klein: An
              * dieser Stelle will jemand einen Termin, kein Konto. Ganz
              * verschweigen wäre trotzdem falsch – manche legen lieber
              * erst eines an.
              */
             . (Kundenlogin::registrierungOffen()
                ? ' <a href="' . Util::attr(self::url('/portal/registrieren.php'))
                  . '">Oder ein Konto anlegen.</a>'
                : '')
             . '</p>'
             . '</div></details>';
    }

    /**
     * Findet einen Kunden zur E-Mail-Adresse oder legt ihn an.
     *
     * Eine Online-Buchung darf keinen zweiten Datensatz zu einer Person
     * erzeugen, die es schon gibt – sonst zerfällt die Kundenakte in
     * Bruchstücke, und niemand räumt das je wieder auf.
     */
    public static function kundeFinden(string $email, string $vorname, string $nachname, string $telefon = ''): int
    {
        $email = strtolower(trim($email));
        if ($email === '') {
            return 0;
        }
        $vorhanden = Tenant::one('customers', 'email = :e', ['e' => $email]);
        if ($vorhanden !== null) {
            if ($telefon !== '' && (string) $vorhanden['telefon'] === '') {
                Tenant::update('customers', (int) $vorhanden['id'], ['telefon' => $telefon]);
            }
            Customers::aktivitaet((int) $vorhanden['id']);
            return (int) $vorhanden['id'];
        }
        return Customers::speichern([
            'vorname'  => $vorname,
            'nachname' => $nachname,
            'email'    => $email,
            'telefon'  => $telefon,
            'status'   => 'aktiv',
            'quelle'   => 'Website',
        ]);
    }

    /**
     * Hält Formulare aus, die ein Skript abschickt.
     *
     * Zwei Prüfungen ohne Rätselbilder: ein Feld, das kein Mensch sieht und
     * nur ein Skript ausfüllt, und eine Mindestdauer – wer ein Formular in
     * unter zwei Sekunden ausfüllt, hat es nicht gelesen.
     */
    public static function istRoboter(): bool
    {
        if (trim((string) ($_POST['website'] ?? '')) !== '') {
            return true;
        }
        $begonnen = (int) ($_POST['begonnen'] ?? 0);
        return $begonnen > 0 && time() - $begonnen < 2;
    }

    /** Protokolliert eine Einwilligung mit Wortlaut – Artikel 7 Absatz 1 DSGVO. */
    public static function einwilligung(int $kundeId, int $leadId, string $typ, string $wortlaut, string $quelle): void
    {
        Tenant::insert('consents', [
            'customer_id' => $kundeId,
            'lead_id'     => $leadId,
            'typ'         => $typ,
            'erteilt'     => 1,
            'text'        => $wortlaut,
            'quelle'      => $quelle,
            'ip'          => '',          // die IP selbst wird nicht gespeichert
            'erstellt'    => Util::jetzt(),
        ]);
    }
}
