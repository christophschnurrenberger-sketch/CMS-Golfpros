<?php
/**
 * Community – Gruppen, Beiträge, Challenges.
 *
 * Optional und bewusst klein gehalten. Eine Community lebt nicht von
 * Funktionen, sondern davon, dass jemand sie betreut – deshalb gibt es
 * hier keine Foren, keine privaten Nachrichten und keine Benachrichtigungs-
 * kaskaden, sondern einen Ort für Beiträge, Challenges und eine Rangliste.
 */
final class Community
{
    public static function gruppen(): array
    {
        return Tenant::all('community_groups', '', [], 'name');
    }

    public static function beitraege(int $gruppeId = 0, int $limit = 30): array
    {
        $wo = $gruppeId > 0 ? 'group_id = :g' : '';
        $p  = $gruppeId > 0 ? ['g' => $gruppeId] : [];
        return Tenant::all('community_posts', $wo, $p, 'angeheftet DESC, id DESC', $limit);
    }

    public static function autorName(array $beitrag): string
    {
        return (string) $beitrag['autor_typ'] === 'user'
            ? Auth::trainerName((int) $beitrag['autor_id'])
            : Customers::nameVonId((int) $beitrag['autor_id']);
    }

    public static function beitragSchreiben(int $gruppeId, string $text, string $autorTyp = 'user', int $autorId = 0): int
    {
        $id = Tenant::insert('community_posts', [
            'group_id' => $gruppeId, 'autor_typ' => $autorTyp,
            'autor_id' => $autorId ?: Auth::id(), 'text' => $text,
        ]);
        if ($gruppeId > 0) {
            $g = Tenant::find('community_groups', $gruppeId);
            if ($g) {
                Tenant::update('community_groups', $gruppeId, ['mitglieder' => (int) $g['mitglieder']]);
            }
        }
        if ($autorTyp === 'customer' && $autorId > 0) {
            Gamification::punkte($autorId, 5, 'Beitrag geschrieben');
        }
        return $id;
    }

    public static function kommentare(int $postId): array
    {
        return Tenant::all('community_comments', 'post_id = :p', ['p' => $postId], 'id');
    }

    /* ---------------------------------------------------- Challenges -- */

    public static function challenges(bool $nurAktive = true): array
    {
        $wo = $nurAktive ? 'aktiv = 1 AND (ende = "" OR ende >= :heute)' : '';
        return Tenant::all('challenges', $wo, $nurAktive ? ['heute' => Util::heute()] : [], 'start DESC');
    }

    public static function teilnehmen(int $challengeId, int $kundeId): void
    {
        if (Tenant::count('challenge_participants', 'challenge_id = :c AND customer_id = :k',
            ['c' => $challengeId, 'k' => $kundeId]) > 0) {
            return;
        }
        Tenant::insert('challenge_participants', [
            'challenge_id' => $challengeId, 'customer_id' => $kundeId, 'fortschritt' => 0,
        ]);
    }

    public static function fortschritt(int $challengeId, int $kundeId, int $wert): void
    {
        $t = Tenant::one('challenge_participants', 'challenge_id = :c AND customer_id = :k',
            ['c' => $challengeId, 'k' => $kundeId]);
        if (!$t) {
            return;
        }
        $challenge = Tenant::find('challenges', $challengeId);
        $satz = ['fortschritt' => $wert];
        if ($challenge && $wert >= (int) $challenge['ziel_wert'] && $t['abgeschlossen'] === null) {
            $satz['abgeschlossen'] = Util::jetzt();
            Gamification::punkte($kundeId, (int) $challenge['xp'], 'Challenge geschafft');
        }
        Tenant::update('challenge_participants', (int) $t['id'], $satz);
    }

    public static function rangliste(int $challengeId, int $limit = 10): array
    {
        return DB::all(
            'SELECT p.*, c.vorname, c.nachname FROM challenge_participants p
             JOIN customers c ON c.id = p.customer_id
             WHERE p.workspace_id = :w AND p.challenge_id = :c
             ORDER BY p.fortschritt DESC, p.abgeschlossen LIMIT ' . (int) $limit,
            ['w' => Tenant::id(), 'c' => $challengeId]
        );
    }

    public static function standardAnlegen(): void
    {
        if (Tenant::count('community_groups') > 0) {
            return;
        }
        Tenant::insert('community_groups', [
            'name' => 'Allgemein',
            'beschreibung' => 'Ankündigungen, Tipps und Fragen rund ums Training.',
            'privat' => 0,
        ]);
        Tenant::insert('community_groups', [
            'name' => 'Platzreife-Gruppe',
            'beschreibung' => 'Für alle im Platzreifekurs – Fragen, Termine, gegenseitige Motivation.',
            'privat' => 0,
        ]);
        Tenant::insert('challenges', [
            'titel' => '7 Tage Putting', 'symbol' => '🎯',
            'beschreibung' => 'Sieben Tage in Folge zehn Minuten putten. Kurz, aber jeden Tag.',
            'ziel_art' => 'tage', 'ziel_wert' => 7,
            'start' => Util::heute(), 'ende' => date('Y-m-d', strtotime('+30 days')),
            'xp' => 150, 'aktiv' => 1,
        ]);
        Tenant::insert('challenges', [
            'titel' => 'Winterfit', 'symbol' => '❄',
            'beschreibung' => 'Zwölf Fitnesseinheiten in sechs Wochen – damit im Frühjahr nichts fehlt.',
            'ziel_art' => 'einheiten', 'ziel_wert' => 12,
            'start' => Util::heute(), 'ende' => date('Y-m-d', strtotime('+60 days')),
            'xp' => 300, 'aktiv' => 1,
        ]);
    }
}
