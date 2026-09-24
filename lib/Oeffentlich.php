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
        $parameter = self::mitMandant($parameter);
        return App::url($pfad) . ($parameter !== [] ? '?' . http_build_query($parameter) : '');
    }

    /** Dasselbe mit Schema und Host – für E-Mails und strukturierte Daten. */
    public static function urlAbsolut(string $pfad, array $parameter = []): string
    {
        $parameter = self::mitMandant($parameter);
        return App::absolut($pfad) . ($parameter !== [] ? '?' . http_build_query($parameter) : '');
    }

    private static function mitMandant(array $parameter): array
    {
        if ((string) (Tenant::workspace()['domain'] ?? '') === '') {
            $parameter = ['w' => (string) (Tenant::workspace()['slug'] ?? '')] + $parameter;
        }
        return $parameter;
    }

    /**
     * Seite im Gewand der Website. Nutzt denselben Kopf und Fuß wie der
     * Renderer – ein Buchungsschritt, der plötzlich anders aussieht als
     * die Seite davor, kostet Vertrauen und damit Buchungen.
     *
     * Vorgänge – Buchen, Kaufen, Anfragen – bleiben aus den Suchmaschinen
     * heraus. Seiten, die gefunden werden sollen, wie der Reisekatalog,
     * sagen es mit `index => true` und geben ein Vorschaubild mit.
     */
    public static function seite(string $titel, string $inhalt, array $o = []): never
    {
        $pseudoSeite = [
            'id' => 0, 'titel' => $titel, 'slug' => (string) ($o['slug'] ?? ''), 'startseite' => 0,
            'seo' => Util::json(['titel' => $titel, 'beschreibung' => (string) ($o['beschreibung'] ?? ''),
                                 'bild' => (string) ($o['bild'] ?? ''), 'index' => !empty($o['index'])]),
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
     * Der Buchungskalender: freie Tage zum Anklicken, darunter die Zeiten.
     *
     * Steht sowohl im Baustein auf der Website als auch auf buchen.php –
     * derselbe Aufbau, dieselben Regeln, eine Stelle. Zwei Fassungen
     * nebeneinander wären beim nächsten Feinschliff sofort auseinander.
     *
     * Kein Monatsgitter mit leeren Anfangszellen: Wer buchen will, denkt
     * in „diese und die nächsten Wochen", nicht in Kalendermonaten.
     * Erster Tag ist immer der Montag dieser Woche, damit die Spalten
     * fluchten.
     *
     * Ohne JavaScript stehen alle freien Tage mit ihren Uhrzeiten
     * untereinander – vollständig, nur lang. Erst site.js macht daraus
     * einen Kalender mit je einem offenen Tag. Deshalb ist hier nichts
     * versteckt.
     *
     * @param  array<string,mixed> $service Leistung aus `services`
     * @return array{html: string, frei: int} frei = Tage mit freien Zeiten
     */
    public static function kalender(array $service, int $wochen = 4): array
    {
        $sid    = (int) $service['id'];
        $wochen = max(1, min(8, $wochen));
        /* Dasselbe Raster wie in der Buchung selbst: Viertelstunden bei
           kurzen Einheiten, halbe bei langen. Ein leerer Tag im
           Viertelstundentakt wäre eine Wand aus vierzig Knöpfen. */
        $raster = (int) $service['dauer_min'] >= 60 ? 30 : 15;

        $heute = Util::heute();
        /* Nicht über strtotime('monday this week') – das liefert am
           Sonntag den Montag der Folgewoche. */
        $wochentag = (int) date('N', strtotime($heute));
        $ersterTag = date('Y-m-d', strtotime($heute . ' -' . ($wochentag - 1) . ' days'));

        $slug   = (string) (Tenant::workspace()['slug'] ?? '');
        $zellen = '';
        $listen = '';
        $frei   = 0;
        $tage   = 7 * $wochen;

        for ($i = 0; $i < $tage; $i++) {
            $tag  = date('Y-m-d', strtotime($ersterTag . ' +' . $i . ' days'));
            $zahl = (int) date('j', strtotime($tag));
            /* Der Erste eines Monats nennt ihn mit, sonst wüsste man beim
               Übergang nicht, in welchem Monat man klickt. */
            $marke = $zahl === 1
                ? '<span class="buchkal__monat">'
                  . Util::h(mb_substr(Util::monatName((int) date('n', strtotime($tag))), 0, 3)) . '</span>'
                : '';

            /* Heute bekommt eine Marke, egal ob frei oder nicht – ohne sie
               sucht man im Gitter erst, wo man gerade steht. */
            $istHeute = $tag === $heute ? ' ist-heute' : '';

            if ($tag < $heute) {
                $zellen .= '<span class="buchkal__tag ist-vorbei" aria-hidden="true">'
                         . '<span class="buchkal__zahl">' . $zahl . '</span></span>';
                continue;
            }

            $zeiten = Bookings::freieZeiten($sid, $tag, 0, $raster);
            if ($zeiten === []) {
                $zellen .= '<span class="buchkal__tag ist-zu' . $istHeute . '">'
                         . $marke . '<span class="buchkal__zahl">' . $zahl . '</span></span>';
                continue;
            }
            $frei++;

            $knoepfe = '';
            foreach ($zeiten as $z) {
                $ziel = App::url('/buchen.php') . '?' . http_build_query(array_filter([
                    'w'          => $slug,
                    'service_id' => $sid,
                    'start'      => (string) $z['start'],
                    'trainer_id' => (int) $z['trainer_id'],
                ]));
                $knoepfe .= '<a class="zeitknopf" href="' . Util::attr($ziel) . '">'
                          . Util::h(Util::uhrzeit((string) $z['start'])) . '</a>';
            }

            /* Die Beschriftung für Vorleseprogramme nennt den ganzen Tag:
               Eine Zahl im Gitter allein sagt ihnen nichts. */
            $vorlesen = Util::datumLang($tag) . ', ' . count($zeiten)
                      . (count($zeiten) === 1 ? ' freie Zeit' : ' freie Zeiten');
            $zellen .= '<button type="button" class="buchkal__tag ist-frei' . $istHeute . '"'
                     . ' data-tag="' . Util::attr($tag) . '"'
                     . ' aria-controls="' . Util::attr('bk' . $sid . '-' . $tag) . '"'
                     . ' aria-label="' . Util::attr($vorlesen) . '">'
                     . $marke . '<span class="buchkal__zahl">' . $zahl . '</span>'
                     . '<span class="buchkal__anzahl">' . count($zeiten)
                     . '<span class="buchkal__frei-wort"> frei</span></span></button>';

            $listen .= '<div class="buchkal__tagzeiten" id="' . Util::attr('bk' . $sid . '-' . $tag) . '"'
                     . ' data-tag="' . Util::attr($tag) . '">'
                     . '<p class="buchkal__datum">' . Util::h(Util::datumLang($tag)) . '</p>'
                     . '<div class="buchkal__reihe">' . $knoepfe . '</div></div>';
        }

        if ($frei === 0) {
            return ['html' => '', 'frei' => 0];
        }

        $letzter = date('Y-m-d', strtotime($ersterTag . ' +' . ($tage - 1) . ' days'));
        $spanne  = Util::h(Util::monatName((int) date('n', strtotime($ersterTag))));
        if (date('n', strtotime($letzter)) !== date('n', strtotime($ersterTag))) {
            $spanne .= ' – ' . Util::h(Util::monatName((int) date('n', strtotime($letzter))));
        }
        $spanne .= ' ' . date('Y', strtotime($letzter));

        $wochentage = '';
        foreach (['Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa', 'So'] as $wt) {
            $wochentage .= '<span>' . $wt . '</span>';
        }

        return [
            'frei' => $frei,
            'html' => '<div class="buchkal" data-buchkal>'
                    . '<div class="buchkal__kopf"><span class="buchkal__spanne">' . $spanne . '</span>'
                    . '<span class="buchkal__hinweis">' . (int) $service['dauer_min'] . ' Minuten · '
                    . Util::h(Util::geldKurz((int) $service['preis_cent'])) . '</span></div>'
                    . '<div class="buchkal__wochentage">' . $wochentage . '</div>'
                    . '<div class="buchkal__gitter">' . $zellen . '</div>'
                    . '<div class="buchkal__zeiten">' . $listen . '</div>'
                    . '</div>',
        ];
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
