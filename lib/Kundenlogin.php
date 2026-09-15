<?php
/**
 * Die Anmeldung des Kunden – für das Portal und für die öffentlichen Seiten.
 *
 * Es gibt zwei Wege hinein, und das ist Absicht:
 *
 *   **Der Link aus der E-Mail.** Jeder Kunde bekommt ihn mit seiner
 *   Terminbestätigung. Er braucht kein Passwort, kein Merken, keine
 *   Registrierung – und genau deshalb benutzt ihn auch der Teil der
 *   Kundschaft, der mit Konten nichts anfangen kann.
 *
 *   **E-Mail und Passwort.** Für alle, die sich eines gesetzt haben und
 *   nicht jedes Mal die alte Mail suchen wollen.
 *
 * Beide führen zur selben Sitzung. Wer sich im Portal angemeldet hat, ist
 * beim Buchen angemeldet und umgekehrt – für den Kunden ist das eine
 * Anmeldung, und zwei getrennte wären eine Zumutung.
 *
 * Diese Klasse ist die einzige Stelle, die `$_SESSION['portal_kunde']`
 * anfasst. Zwei Fassungen derselben Sitzungslogik gingen früher oder
 * später auseinander, und dann hinge jemand halb angemeldet fest.
 */
final class Kundenlogin
{
    private const KUNDE     = 'portal_kunde';
    private const WORKSPACE = 'portal_workspace';

    /** @var array<string,mixed>|null Einmal geladen, dann gemerkt. */
    private static ?array $kunde = null;
    private static bool $geladen = false;

    /* ------------------------------------------------------- Zustand --- */

    public static function angemeldet(): bool
    {
        return self::kunde() !== null;
    }

    /**
     * Der angemeldete Kunde – oder null.
     *
     * Nebenwirkung mit Absicht: Steht noch kein Mandant fest, wird der
     * des Kunden gesetzt. Das Portal kennt weder Domain noch ?w=, dort
     * ist der Kunde die einzige Auskunft darüber, um welchen Betrieb es
     * geht.
     */
    public static function kunde(): ?array
    {
        if (self::$geladen) {
            return self::$kunde;
        }
        self::$geladen = true;

        Auth::start();
        $kundeId     = (int) ($_SESSION[self::KUNDE] ?? 0);
        $workspaceId = (int) ($_SESSION[self::WORKSPACE] ?? 0);
        if ($kundeId <= 0 || $workspaceId <= 0 || !self::passtZumMandanten($workspaceId)) {
            return null;
        }

        Tenant::setzen($workspaceId);
        $treffer = Tenant::find('customers', $kundeId);

        /* Gelöschter oder stillgelegter Kunde: Sitzung beenden, statt mit
           einem Datensatz zu arbeiten, den es nicht mehr gibt. */
        if ($treffer === null || (string) $treffer['status'] === 'geloescht') {
            self::abmelden(false);
            return null;
        }

        self::$kunde = $treffer;
        return self::$kunde;
    }

    public static function id(): int
    {
        $k = self::kunde();
        return $k !== null ? (int) $k['id'] : 0;
    }

    /* -------------------------------------------------------- Wege rein */

    /**
     * Anmeldung mit dem Schlüssel aus der E-Mail.
     *
     * Der Schlüssel ist 16 Byte Zufall und steht in der Adresse. Deshalb
     * wird er nach dem Einlösen aus der Adresszeile genommen – er soll
     * nicht im Verlauf des Browsers stehen und nicht im Referer an fremde
     * Server gehen.
     */
    public static function mitToken(string $token): bool
    {
        $token = trim($token);
        if ($token === '') {
            return false;
        }
        $treffer = DB::one(
            "SELECT * FROM customers WHERE portal_token = :t AND portal_token != '' AND status != 'geloescht'",
            ['t' => $token]
        );
        if ($treffer === null || !self::passtZumMandanten((int) $treffer['workspace_id'])) {
            return false;
        }
        self::sitzungSetzen($treffer);
        return true;
    }

    /**
     * Anmeldung mit E-Mail und Passwort.
     *
     * Gibt absichtlich nur wahr oder falsch zurück, ohne zu sagen, woran
     * es lag. Wer aus „Passwort falsch" gegen „Adresse unbekannt"
     * unterscheiden kann, hat ein Kundenverzeichnis.
     */
    public static function mitPasswort(string $email, string $passwort): bool
    {
        $email = strtolower(trim($email));
        if ($email === '' || $passwort === '') {
            return false;
        }
        $treffer = DB::one(
            "SELECT * FROM customers WHERE email = :e AND portal_passwort != '' AND status != 'geloescht'",
            ['e' => $email]
        );
        if ($treffer === null) {
            /*
             * Auch ohne Treffer einmal prüfen. Sonst antwortet der Server
             * für unbekannte Adressen messbar schneller – gemessen rund
             * 260 Millisekunden Unterschied –, und damit ließe sich von
             * außen erraten, wer hier Kunde ist.
             *
             * Der Hash unten gehört zu einer Zeichenkette aus dem
             * Zufallsgenerator, die nie jemand kannte; er kann also nie
             * passen. Er trägt dieselbe Kostenstufe wie ein echtes
             * Kundenpasswort (PASSWORD_DEFAULT), damit die Fehlprobe
             * genauso lange dauert wie eine echte.
             */
            password_verify($passwort, '$2y$12$FPrItkMNqON1CKZBjWXrj.WFDaGh0nozQNY3jrhLg4WsxX5o0uPhK');
            return false;
        }
        if (!password_verify($passwort, (string) $treffer['portal_passwort'])) {
            return false;
        }
        if (!self::passtZumMandanten((int) $treffer['workspace_id'])) {
            return false;
        }
        self::sitzungSetzen($treffer);
        return true;
    }

    /** Hat dieser Kunde überhaupt ein Passwort gesetzt? */
    public static function hatPasswort(array $kunde): bool
    {
        return trim((string) ($kunde['portal_passwort'] ?? '')) !== '';
    }

    /* -------------------------------------------------- Konto anlegen --- */

    /**
     * Ist die Selbstregistrierung für diesen Betrieb offen?
     *
     * Standardmäßig ja. Wer sie abschaltet, hat meist einen guten Grund:
     * Ein Pro mit geschlossenem Kundenkreis will keine fremden Datensätze
     * in seiner Kartei. Die Buchung als Gast bleibt davon unberührt – die
     * ist kein Konto.
     */
    public static function registrierungOffen(): bool
    {
        return (bool) Tenant::einstellung('registrierung_offen', true);
    }

    /**
     * Ein Kundenkonto anlegen, ohne dass ein Termin im Spiel ist.
     *
     * Der Rückgabewert sagt, was die Seite anzeigen soll:
     *
     *   'fehler'     – die Eingaben stimmen nicht, Meldung steht daneben
     *   'angemeldet' – Konto ist da, der Kunde ist eingeloggt
     *   'mail'       – wir haben eine E-Mail geschickt und sagen sonst nichts
     *
     * Der dritte Fall ist der interessante. Ist die Adresse hier schon
     * bekannt, darf die Seite das **nicht** sagen: Sonst könnte jeder
     * durchprobieren, wer bei diesem Pro Kunde ist, und das ist eine
     * Kundenliste. Die Adresse bekommt stattdessen eine Mail mit ihrem
     * Zugangslink – wer sie wirklich besitzt, kommt damit hinein, alle
     * anderen erfahren nichts. Nach außen sieht dieser Fall genauso aus
     * wie eine frische Registrierung.
     *
     * Ein bestehendes Konto wird dabei nie überschrieben. „Registrieren"
     * mit einer fremden Adresse wäre sonst eine Übernahme.
     *
     * @param array<string,mixed> $d vorname, nachname, email, telefon,
     *                               passwort, einwilligung
     * @return array{0:string,1:string}
     */
    public static function registrieren(array $d): array
    {
        if (!self::registrierungOffen()) {
            return ['fehler', 'Ein Konto lässt sich hier zurzeit nicht selbst anlegen.'];
        }

        $vorname  = trim((string) ($d['vorname'] ?? ''));
        $nachname = trim((string) ($d['nachname'] ?? ''));
        $email    = strtolower(trim((string) ($d['email'] ?? '')));
        $telefon  = trim((string) ($d['telefon'] ?? ''));
        $passwort = (string) ($d['passwort'] ?? '');

        if ($vorname === '' || $nachname === '') {
            return ['fehler', 'Bitte Vor- und Nachnamen angeben.'];
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['fehler', 'Diese E-Mail-Adresse sieht nicht richtig aus.'];
        }
        [$okay, $meldung] = Auth::passwortPruefen($passwort);
        if (!$okay) {
            return ['fehler', $meldung];
        }
        if (empty($d['einwilligung'])) {
            return ['fehler', 'Ohne die Einwilligung zur Verarbeitung der Angaben geht es leider nicht.'];
        }

        /*
         * Adresse schon bekannt: nichts anlegen, nichts überschreiben,
         * nichts verraten – nur den Zugangslink an die Adresse selbst.
         */
        $vorhanden = Tenant::one('customers', 'email = :e', ['e' => $email]);
        if ($vorhanden !== null) {
            /* Über anKunden(), damit die Mail in seiner Akte steht – der Pro
               soll sehen, dass da jemand nach seinem Zugang gesucht hat. */
            Mail::anKunden($vorhanden, 'Dein Zugang bei ' . Tenant::name(),
                "Hallo " . (string) $vorhanden['vorname'] . ",\n\n"
                . "unter dieser Adresse gibt es schon einen Zugang. Ein zweites Konto\n"
                . "braucht es also nicht – mit diesem Link kommst du direkt hinein:\n\n"
                . Customers::portalLink($vorhanden) . "\n\n"
                . "Dort kannst du auch ein Passwort setzen oder ein neues vergeben.\n\n"
                . "Hast du das nicht angefordert, ignoriere diese Mail einfach – es\n"
                . "wurde nichts geändert.\n\n"
                . Tenant::name());
            return ['mail', 'Wir haben dir eine E-Mail geschickt. Schau bitte in dein Postfach – '
                          . 'der Link darin bringt dich in deinen Bereich.'];
        }

        /* Über Customers::speichern(), damit der Pro seine Benachrichtigung
           bekommt und die Automationen anspringen wie bei jedem Neukunden. */
        $kundeId = Customers::speichern([
            'vorname'  => $vorname,
            'nachname' => $nachname,
            'email'    => $email,
            'telefon'  => $telefon,
            'status'   => 'aktiv',
            'quelle'   => 'Registrierung',
        ]);
        if ($kundeId === 0) {
            return ['fehler', 'Das Konto konnte nicht angelegt werden. Bitte später noch einmal versuchen.'];
        }
        /* Das Passwort geht bewusst nicht durch speichern(): Diese Spalte
           steht dort nicht in der Liste der erlaubten Felder, damit kein
           Formular sie je mitschicken kann. */
        Tenant::update('customers', $kundeId, ['portal_passwort' => Auth::hash($passwort)]);

        Oeffentlich::einwilligung($kundeId, 0, 'konto',
            'Einwilligung zur Verarbeitung der Angaben für den Kundenzugang.', 'registrieren.php');

        $kunde = Tenant::find('customers', $kundeId);
        if ($kunde === null) {
            return ['fehler', 'Das Konto konnte nicht angelegt werden. Bitte später noch einmal versuchen.'];
        }

        Mail::anKunden($kunde, 'Willkommen bei ' . Tenant::name(),
            "Hallo " . $vorname . ",\n\n"
            . "dein Zugang steht. Termine, Trainingsplan und Unterlagen findest du hier:\n\n"
            . Customers::portalLink($kunde) . "\n\n"
            . "Der Link funktioniert auch ohne Passwort – bewahre ihn also wie einen\n"
            . "Schlüssel auf.\n\n"
            . Tenant::name());

        self::sitzungSetzen($kunde);
        return ['angemeldet', ''];
    }

    /* ------------------------------------------------------------ raus */

    public static function abmelden(bool $weiter = true): void
    {
        Auth::start();
        unset($_SESSION[self::KUNDE], $_SESSION[self::WORKSPACE]);
        self::$kunde = null;
        self::$geladen = true;

        /*
         * Nur die Kundenanmeldung fällt, nicht die ganze Sitzung: Im
         * Backend kann gleichzeitig ein Mitarbeiter angemeldet sein, und
         * der soll beim Abmelden eines Kunden nicht mit hinausfliegen.
         */
        if ($weiter) {
            App::weiter('/portal/');
        }
    }

    /* ------------------------------------------------------------ intern */

    /**
     * Darf dieser Kunde hier angemeldet sein?
     *
     * Steht der Mandant schon fest – auf den öffentlichen Seiten aus
     * Domain oder ?w= –, dann gilt er, und ein Kunde aus einem anderen
     * Betrieb ist hier schlicht nicht angemeldet. Ohne diese Prüfung
     * hätte seine Sitzung die Seite unbemerkt auf seinen eigenen Betrieb
     * umgeschaltet: Der Besucher sieht die Leistungen der einen
     * Golfschule und bucht in der anderen.
     *
     * Seine Sitzung bleibt bestehen – im eigenen Betrieb ist er weiter
     * angemeldet. Er ist nur hier niemand.
     */
    private static function passtZumMandanten(int $workspaceId): bool
    {
        return !Tenant::gesetzt() || Tenant::id() === $workspaceId;
    }

    private static function sitzungSetzen(array $kunde): void
    {
        Auth::start();
        /* Gegen das Übernehmen einer fremden, bereits bekannten Sitzungs-
           kennung – dasselbe, was die Anmeldung im Backend tut. */
        session_regenerate_id(true);

        $_SESSION[self::KUNDE]     = (int) $kunde['id'];
        $_SESSION[self::WORKSPACE] = (int) $kunde['workspace_id'];

        Tenant::setzen((int) $kunde['workspace_id']);
        self::$kunde   = $kunde;
        self::$geladen = true;

        Customers::aktivitaet((int) $kunde['id']);
    }
}
