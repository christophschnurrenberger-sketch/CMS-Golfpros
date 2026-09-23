<?php
/**
 * Betreiberlog – das Protokoll der Betreiberzentrale.
 *
 * Getrennt vom Protokoll der Instanzen (`audit_log`), aus zwei Gründen:
 *
 *   **Es gehört niemandem aus einer Instanz.** Ein Inhaber sieht sein
 *   Änderungsprotokoll; was die Betreiber über ihn eintragen – Paket
 *   geändert, gesperrt, Support geöffnet mit Grund –, geht ihn in dieser
 *   Form nichts an. (Dass der Support da war, erfährt er trotzdem: Davon
 *   steht eine Zeile in seinem eigenen Protokoll.)
 *
 *   **Es überlebt die Instanz.** Beim Löschen verschwindet jede Zeile mit
 *   `workspace_id`. Dieses Protokoll trägt deshalb `instanz_id` – der
 *   Eintrag „Instanz gelöscht" muss bleiben, sonst ist gerade die
 *   folgenreichste Handlung die, von der nichts übrig ist.
 *
 * Es wird nur angehängt. Es gibt keine Methode zum Ändern oder Löschen,
 * und die Datenbank verweigert beides zusätzlich über Auslöser.
 *
 * Was hier nie hineinkommt: Passwörter, Passwort-Hashes, Tokens und
 * Schlüssel. `saeubern()` nimmt sie aus jedem Vorher/Nachher heraus,
 * auch wenn ein Aufrufer sie aus Versehen mitgibt.
 */
final class Betreiberlog
{
    /** Aktion => [Bezeichnung, Schwere] */
    public const AKTIONEN = [
        'TENANT_CREATED'          => ['Instanz angelegt', 'INFO'],
        'TENANT_UPDATED'          => ['Instanz bearbeitet', 'INFO'],
        'TENANT_ACTIVATED'        => ['Instanz aktiviert', 'INFO'],
        'TENANT_PAUSED'           => ['Instanz pausiert', 'WARNING'],
        'TENANT_LOCKED'           => ['Instanz gesperrt', 'WARNING'],
        'TENANT_ARCHIVED'         => ['Instanz archiviert', 'WARNING'],
        'TENANT_DELETED'          => ['Instanz gelöscht', 'CRITICAL'],
        'PLAN_CHANGED'            => ['Paket geändert', 'INFO'],
        'SUBSCRIPTION_CHANGED'    => ['Laufzeit geändert', 'INFO'],
        'PACKAGE_CREATED'         => ['Paket angelegt', 'INFO'],
        'PACKAGE_UPDATED'         => ['Paket bearbeitet', 'INFO'],
        'USER_CREATED'            => ['Benutzer angelegt', 'INFO'],
        'USER_UPDATED'            => ['Benutzer geändert', 'INFO'],
        'USER_DISABLED'           => ['Benutzer deaktiviert', 'WARNING'],
        'USER_INVITED'            => ['Einladung verschickt', 'INFO'],
        'SUPPORT_SESSION_STARTED' => ['Support Mode begonnen', 'WARNING'],
        'SUPPORT_SESSION_ENDED'   => ['Support Mode beendet', 'INFO'],
        'NOTE_ADDED'              => ['Notiz hinzugefügt', 'INFO'],
        'EXPORT_CREATED'          => ['Export erstellt', 'WARNING'],
        'SETTINGS_CHANGED'        => ['Einstellungen geändert', 'INFO'],
        'OPERATOR_CREATED'        => ['Betreiber angelegt', 'CRITICAL'],
        'OPERATOR_UPDATED'        => ['Betreiber geändert', 'CRITICAL'],
        'MASTER_LOGIN'            => ['Anmeldung Betreiberzentrale', 'INFO'],
        'MASTER_LOGIN_FAILED'     => ['Anmeldung fehlgeschlagen', 'WARNING'],
        'MASTER_LOGOUT'           => ['Abmeldung Betreiberzentrale', 'INFO'],
        'ACCESS_DENIED'           => ['Zugriff verweigert', 'WARNING'],
    ];

    public const SCHWERE = ['INFO' => 'Info', 'WARNING' => 'Warnung', 'CRITICAL' => 'Kritisch'];

    public const ERGEBNIS = ['ok' => 'Erfolgreich', 'fehler' => 'Fehlgeschlagen', 'verweigert' => 'Verweigert'];

    private static string $anfrage = '';

    /**
     * Schreibt einen Eintrag.
     *
     * @param array{objekt?:string,objekt_id?:int,instanz_id?:int,vorher?:array|null,nachher?:array|null,
     *              grund?:string,beschreibung?:string,ergebnis?:string,schwere?:string,
     *              betreiber_id?:int,akteur?:string} $o
     */
    public static function schreiben(string $aktion, array $o = []): int
    {
        $schwere = (string) ($o['schwere'] ?? (self::AKTIONEN[$aktion][1] ?? 'INFO'));
        if (!isset(self::SCHWERE[$schwere])) {
            $schwere = 'INFO';
        }
        $ergebnis = (string) ($o['ergebnis'] ?? 'ok');
        if (!isset(self::ERGEBNIS[$ergebnis])) {
            $ergebnis = 'ok';
        }
        return DB::insert('betreiber_log', [
            'zeit'         => Util::jetzt(),
            'betreiber_id' => (int) ($o['betreiber_id'] ?? Betreiber::id()),
            'akteur'       => mb_substr((string) ($o['akteur'] ?? Betreiber::name()), 0, 190),
            'aktion'       => $aktion,
            'schwere'      => $schwere,
            'objekt'       => (string) ($o['objekt'] ?? ''),
            'objekt_id'    => (int) ($o['objekt_id'] ?? 0),
            'instanz_id'   => (int) ($o['instanz_id'] ?? 0),
            'ergebnis'     => $ergebnis,
            'vorher'       => isset($o['vorher']) ? Util::json(self::saeubern((array) $o['vorher'])) : null,
            'nachher'      => isset($o['nachher']) ? Util::json(self::saeubern((array) $o['nachher'])) : null,
            'grund'        => mb_substr(trim((string) ($o['grund'] ?? '')), 0, 1000),
            'beschreibung' => mb_substr((string) ($o['beschreibung'] ?? ''), 0, 1000),
            'anfrage_id'   => self::anfrageId(),
            'ip'           => Util::ip(),
        ]);
    }

    /** Kennung dieser Anfrage – verbindet mehrere Einträge aus einem Klick. */
    public static function anfrageId(): string
    {
        if (self::$anfrage === '') {
            self::$anfrage = substr(Util::token(8), 0, 16);
        }
        return self::$anfrage;
    }

    /**
     * Nimmt alles heraus, was nach Geheimnis aussieht.
     *
     * Absichtlich über den Namen und nicht über eine Liste erlaubter
     * Felder: Kommt morgen eine Spalte `sms_token` dazu, soll sie nicht
     * erst in einer Liste nachgetragen werden müssen, um nicht im
     * Protokoll zu landen.
     *
     * @param array<string,mixed> $daten
     * @return array<string,mixed>
     */
    public static function saeubern(array $daten): array
    {
        foreach ($daten as $k => $v) {
            if (preg_match('/passw|token|secret|geheim|hash|schluessel|api_?key|zwei_faktor/i', (string) $k)) {
                unset($daten[$k]);
                continue;
            }
            if (is_array($v)) {
                $daten[$k] = self::saeubern($v);
            }
        }
        return $daten;
    }

    public static function text(string $aktion): string
    {
        return self::AKTIONEN[$aktion][0] ?? $aktion;
    }

    /**
     * Einträge nach Filter, neueste zuerst.
     *
     * @param array{aktion?:string,schwere?:string,instanz_id?:int,betreiber_id?:int,von?:string,bis?:string,
     *              ergebnis?:string,aktionen?:string[]} $f
     * @return array<int,array<string,mixed>>
     */
    public static function liste(array $f = [], int $limit = 50, int $versatz = 0): array
    {
        [$wo, $p] = self::bedingung($f);
        return DB::all('SELECT * FROM betreiber_log' . $wo . ' ORDER BY id DESC LIMIT '
            . max(1, $limit) . ($versatz > 0 ? ' OFFSET ' . $versatz : ''), $p);
    }

    public static function anzahl(array $f = []): int
    {
        [$wo, $p] = self::bedingung($f);
        return DB::int('SELECT COUNT(*) FROM betreiber_log' . $wo, $p);
    }

    public static function eintrag(int $id): ?array
    {
        return DB::one('SELECT * FROM betreiber_log WHERE id = :id', ['id' => $id]);
    }

    /** @return array{0:string,1:array<string,mixed>} */
    private static function bedingung(array $f): array
    {
        $wo = [];
        $p  = [];
        if (!empty($f['aktion']) && isset(self::AKTIONEN[$f['aktion']])) {
            $wo[] = 'aktion = :aktion';
            $p['aktion'] = $f['aktion'];
        }
        if (!empty($f['aktionen']) && is_array($f['aktionen'])) {
            $teile = [];
            foreach (array_values($f['aktionen']) as $i => $a) {
                if (isset(self::AKTIONEN[$a])) {
                    $teile[] = ':a' . $i;
                    $p['a' . $i] = $a;
                }
            }
            if ($teile !== []) {
                $wo[] = 'aktion IN (' . implode(', ', $teile) . ')';
            }
        }
        if (!empty($f['schwere']) && isset(self::SCHWERE[$f['schwere']])) {
            $wo[] = 'schwere = :schwere';
            $p['schwere'] = $f['schwere'];
        }
        if (!empty($f['ergebnis']) && isset(self::ERGEBNIS[$f['ergebnis']])) {
            $wo[] = 'ergebnis = :ergebnis';
            $p['ergebnis'] = $f['ergebnis'];
        }
        if ((int) ($f['instanz_id'] ?? 0) > 0) {
            $wo[] = 'instanz_id = :instanz';
            $p['instanz'] = (int) $f['instanz_id'];
        }
        if ((int) ($f['betreiber_id'] ?? 0) > 0) {
            $wo[] = 'betreiber_id = :betreiber';
            $p['betreiber'] = (int) $f['betreiber_id'];
        }
        if (!empty($f['von']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $f['von'])) {
            $wo[] = 'zeit >= :von';
            $p['von'] = $f['von'] . ' 00:00:00';
        }
        if (!empty($f['bis']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $f['bis'])) {
            $wo[] = 'zeit <= :bis';
            $p['bis'] = $f['bis'] . ' 23:59:59';
        }
        return [$wo === [] ? '' : ' WHERE ' . implode(' AND ', $wo), $p];
    }

    /**
     * Wie oft hat dieser Betreiber das in den letzten Minuten getan?
     *
     * Die Bremse für folgenreiche Handlungen liest das Protokoll selbst,
     * statt einen eigenen Zähler zu führen: Was hier steht, ist passiert.
     */
    public static function haeufigkeit(string $aktion, int $betreiberId, int $sekunden = 900): int
    {
        return DB::int('SELECT COUNT(*) FROM betreiber_log WHERE aktion = :a AND betreiber_id = :b AND zeit > :seit',
            ['a' => $aktion, 'b' => $betreiberId, 'seit' => date('Y-m-d H:i:s', time() - $sekunden)]);
    }
}
