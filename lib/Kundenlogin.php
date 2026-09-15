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
