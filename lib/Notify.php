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

    /** @return array<int,array<string,mixed>> */
    public static function fuerBenutzer(int $userId, int $limit = 20, bool $nurUngelesen = false): array
    {
        $wo = '(user_id = 0 OR user_id = :u)';
        if ($nurUngelesen) {
            $wo .= ' AND gelesen IS NULL';
        }
        return Tenant::all('notifications', $wo, ['u' => $userId], 'id DESC', $limit);
    }

    public static function ungelesen(int $userId): int
    {
        return Tenant::count('notifications', '(user_id = 0 OR user_id = :u) AND gelesen IS NULL', ['u' => $userId]);
    }

    public static function alsGelesen(int $id): void
    {
        Tenant::update('notifications', $id, ['gelesen' => Util::jetzt()]);
    }

    public static function alleGelesen(int $userId): void
    {
        Tenant::updateWhere('notifications', ['gelesen' => Util::jetzt()],
            '(user_id = 0 OR user_id = :u) AND gelesen IS NULL', ['u' => $userId]);
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
