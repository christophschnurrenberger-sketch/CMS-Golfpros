<?php
/**
 * Support Mode – ein Betreiber sieht eine Instanz mit den Augen ihres Inhabers.
 *
 * Ohne Passwort des Kunden und ohne dass der Kunde es merkt, weil er
 * plötzlich „angemeldet" ist: Die Sitzung wird auf dem Server umgestellt,
 * und sie trägt dabei immer mit, wer wirklich dahintersteht.
 *
 *   - Die Betreibersitzung bleibt bestehen. Endet der Support, ist man
 *     wieder in der Betreiberzentrale, ohne neue Anmeldung.
 *   - Die Sitzung läuft nach einer festen Zeit ab (Einstellungen,
 *     Vorgabe 60 Minuten) – auch dann, wenn jemand vergisst, sie zu
 *     beenden.
 *   - Jede Seite prüft neu: Ist der Betreiber noch angemeldet und aktiv,
 *     gehört der Benutzer noch zu dieser Instanz, ist die Zeit um?
 *   - Beginn und Ende stehen im Betreiberprotokoll, mit Grund und Dauer.
 *     Im Protokoll der Instanz steht, dass der TeePilot Support da war –
 *     jede Änderung in dieser Zeit ist dort als Support gekennzeichnet.
 *   - „Letzte Anmeldung" des Inhabers bleibt unberührt. Er soll nicht
 *     rätseln, wann er sich angemeldet haben soll.
 */
final class Support
{
    private const SITZUNG = 'gp_support';

    /** @var array<string,mixed>|null|false false = noch nicht geprüft */
    private static $geprueft = false;

    /**
     * @return array{0:bool,1:string} [Erfolg, Meldung]
     */
    public static function starten(int $workspaceId, string $grund = ''): array
    {
        if (!Betreiber::darf('support.start')) {
            return [false, 'Keine Berechtigung für den Support Mode.'];
        }
        if (Betreiber::gebremst('SUPPORT_SESSION_STARTED', 20)) {
            return [false, 'Zu viele Support-Sitzungen in kurzer Zeit. Bitte in 15 Minuten erneut versuchen.'];
        }
        $ws = DB::one('SELECT id, name, status FROM workspaces WHERE id = :id', ['id' => $workspaceId]);
        if ($ws === null) {
            return [false, 'Diese Instanz gibt es nicht.'];
        }
        $inhaber = DB::one("SELECT * FROM users WHERE workspace_id = :w AND aktiv = 1
                            ORDER BY CASE rolle WHEN 'owner' THEN 0 WHEN 'admin' THEN 1 ELSE 2 END, id LIMIT 1",
                            ['w' => $workspaceId]);
        if ($inhaber === null || !in_array((string) $inhaber['rolle'], ['owner', 'admin'], true)) {
            return [false, 'Diese Instanz hat keinen aktiven Inhaber oder Administrator, dessen Sicht sich öffnen ließe.'];
        }
        if (self::aktiv()) {
            self::beenden('neue Sitzung');
        }

        $minuten = Plattform::zahl('support_minuten');
        $kennung = substr(Util::token(8), 0, 12);
        session_regenerate_id(true);
        $_SESSION['gp_user'] = (int) $inhaber['id'];
        $_SESSION['gp_zeit'] = time();
        $_SESSION[self::SITZUNG] = [
            'betreiber_id' => Betreiber::id(),
            'betreiber'    => Betreiber::name(),
            'workspace_id' => $workspaceId,
            'user_id'      => (int) $inhaber['id'],
            'beginn'       => time(),
            'bis'          => time() + $minuten * 60,
            'kennung'      => $kennung,
        ];
        self::$geprueft = false;

        Betreiberlog::schreiben('SUPPORT_SESSION_STARTED', [
            'objekt' => 'instanz', 'objekt_id' => $workspaceId, 'instanz_id' => $workspaceId,
            'grund' => $grund,
            'nachher' => ['als_benutzer' => (int) $inhaber['id'], 'rolle' => (string) $inhaber['rolle'],
                          'minuten' => $minuten, 'sitzung' => $kennung, 'status' => (string) $ws['status']],
        ]);
        self::inInstanzVermerken($workspaceId, 'support_beginn', 'TeePilot Support hat die Instanz geöffnet');
        return [true, ''];
    }

    /**
     * Beendet die Support-Sitzung. Der Betreiber bleibt angemeldet.
     *
     * @return int die Instanz, in der die Sitzung lief (0, wenn keine lief)
     */
    public static function beenden(string $anlass = 'beendet'): int
    {
        Auth::start();
        $s = $_SESSION[self::SITZUNG] ?? null;
        if (!is_array($s)) {
            return 0;
        }
        $workspaceId = (int) ($s['workspace_id'] ?? 0);
        unset($_SESSION[self::SITZUNG], $_SESSION['gp_user'], $_SESSION['gp_zeit']);
        self::$geprueft = false;

        Betreiberlog::schreiben('SUPPORT_SESSION_ENDED', [
            'objekt' => 'instanz', 'objekt_id' => $workspaceId, 'instanz_id' => $workspaceId,
            'betreiber_id' => (int) ($s['betreiber_id'] ?? 0),
            'akteur' => (string) ($s['betreiber'] ?? ''),
            'nachher' => ['sitzung' => (string) ($s['kennung'] ?? ''), 'anlass' => $anlass,
                          'dauer_minuten' => (int) round((time() - (int) ($s['beginn'] ?? time())) / 60)],
        ]);
        self::inInstanzVermerken($workspaceId, 'support_ende', 'TeePilot Support hat die Instanz verlassen',
            (int) ($s['betreiber_id'] ?? 0));
        return $workspaceId;
    }

    /**
     * Gilt die Support-Sitzung noch? Beendet sie, wenn nicht.
     *
     * Wird von Auth::benutzer() bei jedem Aufruf gefragt, bevor der
     * Benutzer der Sitzung überhaupt geladen wird.
     *
     * @return array<string,mixed>|null
     */
    public static function pruefen(): ?array
    {
        if (self::$geprueft !== false) {
            return self::$geprueft;
        }
        $s = $_SESSION[self::SITZUNG] ?? null;
        if (!is_array($s)) {
            return self::$geprueft = null;
        }
        $betreiber = Betreiber::benutzer();
        $gueltig = $betreiber !== null
            && (int) $betreiber['id'] === (int) ($s['betreiber_id'] ?? 0)
            && (int) ($_SESSION['gp_user'] ?? 0) === (int) ($s['user_id'] ?? -1)
            && time() < (int) ($s['bis'] ?? 0)
            && DB::int('SELECT COUNT(*) FROM users WHERE id = :u AND workspace_id = :w AND aktiv = 1',
                   ['u' => (int) $s['user_id'], 'w' => (int) $s['workspace_id']]) === 1;
        if (!$gueltig) {
            $anlass = time() >= (int) ($s['bis'] ?? 0) ? 'abgelaufen' : 'ungültig';
            self::beenden($anlass);
            if ($anlass === 'abgelaufen' && $betreiber !== null) {
                App::melden('Die Support-Sitzung ist nach der eingestellten Zeit abgelaufen.', 'info');
            }
            return self::$geprueft = null;
        }
        return self::$geprueft = $s;
    }

    /** Hat die Sitzung überhaupt einen Support-Eintrag? (ohne zu prüfen) */
    public static function vermerkt(): bool
    {
        return session_status() === PHP_SESSION_ACTIVE && is_array($_SESSION[self::SITZUNG] ?? null);
    }

    public static function aktiv(): bool
    {
        return self::vermerkt() && self::pruefen() !== null;
    }

    /** Wer steht dahinter? 0 außerhalb des Support Mode. Für das Protokoll. */
    public static function betreiberId(): int
    {
        if (!self::vermerkt()) {
            return 0;
        }
        return (int) (self::pruefen()['betreiber_id'] ?? 0);
    }

    public static function daten(): ?array
    {
        return self::vermerkt() ? self::pruefen() : null;
    }

    public static function restMinuten(): int
    {
        $s = self::daten();
        return $s === null ? 0 : max(0, (int) ceil(((int) $s['bis'] - time()) / 60));
    }

    /** Eine Zeile im Protokoll der Instanz – das sieht auch der Kunde. */
    private static function inInstanzVermerken(int $workspaceId, string $aktion, string $text, int $betreiberId = 0): void
    {
        if ($workspaceId <= 0) {
            return;
        }
        try {
            DB::insert('audit_log', [
                'workspace_id' => $workspaceId,
                'user_id'      => 0,
                'aktion'       => $aktion,
                'objekt'       => 'workspace',
                'objekt_id'    => $workspaceId,
                'beschreibung' => $text,
                'ip'           => Util::ip(),
                'betreiber_id' => $betreiberId > 0 ? $betreiberId : Betreiber::id(),
                'erstellt'     => Util::jetzt(),
            ]);
        } catch (Throwable $e) {
            // Der Eintrag im Betreiberprotokoll steht in jedem Fall.
        }
    }
}
