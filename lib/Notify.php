<?php
/**
 * Notify – das Benachrichtigungszentrum.
 *
 * Sechs Kategorien, damit die Glocke nicht zum Rauschen wird: Buchung,
 * Zahlung, Kunde, Marketing, System, KI. Jede Meldung trägt einen Link auf
 * die Stelle, an der man etwas tun kann – eine Meldung ohne Ziel ist nur
 * Lärm.
 */
final class Notify
{
    public const KATEGORIEN = [
        'booking'   => ['Buchung', 'bookings'],
        'payment'   => ['Zahlung', 'payments'],
        'customer'  => ['Kunde', 'customers'],
        'marketing' => ['Marketing', 'marketing'],
        'system'    => ['System', 'settings'],
        'ai'        => ['KI-Empfehlung', 'ai'],
        'teepilot'  => ['Von TeePilot', 'invoices'],
    ];

    /**
     * Kategorien, die nicht jeder im Team sieht.
     *
     * Rechnungen von TeePilot an die Instanz gehen den Trainer nichts an –
     * er kann sie auch nicht öffnen. Statt die Meldung beim Schreiben an
     * einzelne Benutzer zu adressieren, wird sie beim Lesen gefiltert:
     * Wer das Recht später bekommt oder verliert, sieht sofort das
     * Richtige, und eine neue Administratorin findet die offene Rechnung
     * genauso wie der Inhaber.
     */
    private const NUR_MIT_RECHT = [
        'teepilot' => 'settings.allgemein',
    ];

    public static function senden(string $kategorie, string $titel, string $text = '', string $link = '', int $userId = 0): int
    {
        return Tenant::insert('notifications', [
            'user_id'   => $userId,
            'kategorie' => $kategorie,
            'titel'     => $titel,
            'text'      => $text,
            'link'      => $link,
        ]);
    }

    /**
     * Eine Meldung an eine bestimmte Instanz – aus der Betreiberzentrale.
     *
     * Dort ist kein Mandant gesetzt (`Tenant::id()` ist 0), `senden()`
     * schriebe also ins Leere. Die Instanz steht deshalb ausdrücklich im
     * Aufruf und nie aus einer Adresse oder einem Formular.
     */
    public static function anInstanz(int $workspaceId, string $kategorie, string $titel, string $text = '', string $link = ''): int
    {
        if ($workspaceId <= 0) {
            return 0;
        }
        return DB::insert('notifications', [
            'workspace_id' => $workspaceId,
            'user_id'      => 0,
            'kategorie'    => $kategorie,
            'titel'        => mb_substr($titel, 0, 200),
            'text'         => $text,
            'link'         => mb_substr($link, 0, 255),
            'erstellt'     => Util::jetzt(),
        ]);
    }

    /** @return array<int,array<string,mixed>> */
    public static function fuerBenutzer(int $userId, int $limit = 20, bool $nurUngelesen = false): array
    {
        [$wo, $p] = self::sichtbar($userId);
        if ($nurUngelesen) {
            $wo .= ' AND gelesen IS NULL';
        }
        return Tenant::all('notifications', $wo, $p, 'id DESC', $limit);
    }

    public static function ungelesen(int $userId): int
    {
        [$wo, $p] = self::sichtbar($userId);
        return Tenant::count('notifications', $wo . ' AND gelesen IS NULL', $p);
    }

    /** Eine Meldung – nur, wenn dieser Benutzer sie überhaupt sehen darf. */
    public static function fuer(int $id, int $userId): ?array
    {
        [$wo, $p] = self::sichtbar($userId);
        return Tenant::one('notifications', 'id = :id AND ' . $wo, $p + ['id' => $id]);
    }

    public static function alsGelesen(int $id): void
    {
        Tenant::update('notifications', $id, ['gelesen' => Util::jetzt()]);
    }

    public static function alleGelesen(int $userId, string $kategorie = ''): void
    {
        [$wo, $p] = self::sichtbar($userId);
        if ($kategorie !== '') {
            $wo .= ' AND kategorie = :kat';
            $p['kat'] = $kategorie;
        }
        Tenant::updateWhere('notifications', ['gelesen' => Util::jetzt()], $wo . ' AND gelesen IS NULL', $p);
    }

    /** Darf der angemeldete Benutzer Meldungen dieser Kategorie sehen? */
    public static function kategorieSichtbar(string $kategorie): bool
    {
        return !isset(self::NUR_MIT_RECHT[$kategorie]) || Auth::darf(self::NUR_MIT_RECHT[$kategorie]);
    }

    /**
     * Die Bedingung „an mich oder an alle – und nur, was ich sehen darf".
     *
     * @return array{0:string,1:array<string,mixed>}
     */
    private static function sichtbar(int $userId): array
    {
        $wo = '(user_id = 0 OR user_id = :u)';
        $p  = ['u' => $userId];
        $i  = 0;
        foreach (array_keys(self::NUR_MIT_RECHT) as $kategorie) {
            if (!self::kategorieSichtbar($kategorie)) {
                $wo .= ' AND kategorie != :nk' . $i;
                $p['nk' . $i++] = $kategorie;
            }
        }
        return [$wo, $p];
    }

    public static function kategorieName(string $kategorie): string
    {
        return self::KATEGORIEN[$kategorie][0] ?? 'System';
    }

    public static function kategorieIcon(string $kategorie): string
    {
        return self::KATEGORIEN[$kategorie][1] ?? 'info';
    }
}
