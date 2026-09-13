<?php
/**
 * Suche – die globale Suche über das ganze System.
 *
 * „Christoph" soll den Kunden finden, seine Termine, seine Rechnungen und
 * seine Trainingspläne – nicht nur die Kundenliste. Deshalb geht die Suche
 * über zehn Tabellen und sortiert die Treffer nach Relevanz, nicht nach
 * Tabellenreihenfolge.
 *
 * Rechte werden geprüft: Wer Rechnungen nicht sehen darf, findet sie auch
 * über die Suche nicht.
 */
final class Suche
{
    /**
     * @return array<int,array{titel:string,unter:string,url:string,gruppe:string,icon:string,gewicht:int}>
     */
    public static function alles(string $begriff, int $limit = 24): array
    {
        $begriff = trim($begriff);
        if (mb_strlen($begriff) < 2) {
            return [];
        }
        $q = '%' . $begriff . '%';
        $treffer = [];

        /* Kunden */
        if (Auth::darf('customers.view')) {
            foreach (Tenant::all('customers',
                '(vorname LIKE :q OR nachname LIKE :q OR email LIKE :q OR telefon LIKE :q OR heimclub LIKE :q)',
                ['q' => $q], 'nachname', 8) as $k) {
                $treffer[] = [
                    'titel' => Customers::name($k),
                    'unter' => trim(implode(' · ', array_filter([
                        (string) $k['email'],
                        (string) $k['hcp'] !== '' ? 'HCP ' . $k['hcp'] : '',
                        (string) $k['heimclub'],
                    ]))),
                    'url' => App::url('/app/kunde.php?id=' . (int) $k['id']),
                    'gruppe' => 'Kunden', 'icon' => Icon::svg('user', 17),
                    'gewicht' => self::gewicht(Customers::name($k), $begriff) + 20,
                ];
            }
        }

        /* Leads */
        if (Auth::darf('leads.view')) {
            foreach (Tenant::all('leads', '(name LIKE :q OR email LIKE :q OR interesse LIKE :q)',
                ['q' => $q], 'id DESC', 5) as $l) {
                $treffer[] = [
                    'titel' => (string) $l['name'],
                    'unter' => Leads::stufeName((string) $l['stufe']) . ' · ' . (string) $l['interesse'],
                    'url' => App::url('/app/lead.php?id=' . (int) $l['id']),
                    'gruppe' => 'Anfragen', 'icon' => Icon::svg('leads', 17),
                    'gewicht' => self::gewicht((string) $l['name'], $begriff) + 12,
                ];
            }
        }

        /* Termine */
        if (Auth::darf('bookings.view')) {
            foreach (Tenant::all('bookings', '(titel LIKE :q OR notiz LIKE :q)', ['q' => $q], 'start DESC', 6) as $b) {
                $treffer[] = [
                    'titel' => (string) $b['titel'],
                    'unter' => Util::datumZeit((string) $b['start'])
                             . ((int) $b['customer_id'] > 0 ? ' · ' . Customers::nameVonId((int) $b['customer_id']) : ''),
                    'url' => App::url('/app/buchung.php?id=' . (int) $b['id']),
                    'gruppe' => 'Termine', 'icon' => Icon::svg('calendar', 17),
                    'gewicht' => self::gewicht((string) $b['titel'], $begriff) + 6,
                ];
            }
            // Termine über den Kundennamen finden
            foreach (DB::all(
                'SELECT b.* FROM bookings b JOIN customers c ON c.id = b.customer_id
                 WHERE b.workspace_id = :w AND (c.vorname LIKE :q OR c.nachname LIKE :q)
                 ORDER BY b.start DESC LIMIT 5',
                ['w' => Tenant::id(), 'q' => $q]) as $b) {
                $treffer[] = [
                    'titel' => (string) $b['titel'] . ' · ' . Customers::nameVonId((int) $b['customer_id']),
                    'unter' => Util::datumZeit((string) $b['start']),
                    'url' => App::url('/app/buchung.php?id=' . (int) $b['id']),
                    'gruppe' => 'Termine', 'icon' => Icon::svg('calendar', 17), 'gewicht' => 8,
                ];
            }
        }

        /* Rechnungen */
        if (Auth::darf('invoices.view')) {
            foreach (Tenant::all('invoices', '(nummer LIKE :q OR empfaenger LIKE :q)', ['q' => $q], 'id DESC', 5) as $r) {
                $treffer[] = [
                    'titel' => 'Rechnung ' . ($r['nummer'] ?: 'Entwurf'),
                    'unter' => Customers::nameVonId((int) $r['customer_id']) . ' · '
                             . Util::geld((int) $r['summe_cent']) . ' · ' . Invoices::statusName((string) $r['status']),
                    'url' => App::url('/app/rechnung.php?id=' . (int) $r['id']),
                    'gruppe' => 'Rechnungen', 'icon' => Icon::svg('invoices', 17),
                    'gewicht' => self::gewicht((string) $r['nummer'], $begriff) + 10,
                ];
            }
        }

        /* Produkte und Pakete */
        if (Auth::darf('products.view')) {
            foreach (Tenant::all('products', '(name LIKE :q OR beschreibung LIKE :q)', ['q' => $q], 'name', 5) as $p) {
                $treffer[] = [
                    'titel' => (string) $p['name'],
                    'unter' => (Commerce::PRODUKT_ARTEN[(string) $p['art']][0] ?? '') . ' · ' . Util::geldKurz((int) $p['preis_cent']),
                    'url' => App::url('/app/produkt.php?id=' . (int) $p['id']),
                    'gruppe' => 'Produkte', 'icon' => Icon::svg('products', 17),
                    'gewicht' => self::gewicht((string) $p['name'], $begriff) + 8,
                ];
            }
        }

        /* Trainingspläne und Übungen */
        if (Auth::darf('training.view') || Auth::darf('training.write')) {
            foreach (Tenant::all('training_plans', 'name LIKE :q', ['q' => $q], 'id DESC', 4) as $t) {
                $treffer[] = [
                    'titel' => (string) $t['name'],
                    'unter' => (int) $t['customer_id'] > 0
                        ? Customers::nameVonId((int) $t['customer_id'])
                        : 'Vorlage · ' . (int) $t['wochen'] . ' Wochen',
                    'url' => App::url('/app/trainingsplan.php?id=' . (int) $t['id']),
                    'gruppe' => 'Training', 'icon' => Icon::svg('training', 17),
                    'gewicht' => self::gewicht((string) $t['name'], $begriff) + 6,
                ];
            }
            foreach (Tenant::all('exercises', '(titel LIKE :q OR beschreibung LIKE :q)', ['q' => $q], 'titel', 4) as $u) {
                $treffer[] = [
                    'titel' => (string) $u['titel'],
                    'unter' => (Training::KATEGORIEN[(string) $u['kategorie']] ?? '') . ' · ' . (int) $u['dauer_min'] . ' Min',
                    'url' => App::url('/app/uebungen.php?id=' . (int) $u['id']),
                    'gruppe' => 'Übungen', 'icon' => Icon::svg('dumbbell', 17),
                    'gewicht' => self::gewicht((string) $u['titel'], $begriff) + 4,
                ];
            }
        }

        /* Videos */
        if (Auth::darf('video.view') || Auth::darf('video.write')) {
            foreach (Tenant::all('videos', 'titel LIKE :q', ['q' => $q], 'id DESC', 4) as $v) {
                $treffer[] = [
                    'titel' => (string) $v['titel'],
                    'unter' => Customers::nameVonId((int) $v['customer_id']) . ' · ' . Util::datum((string) $v['erstellt']),
                    'url' => App::url('/app/videoanalyse.php?video=' . (int) $v['id']),
                    'gruppe' => 'Videos', 'icon' => Icon::svg('video', 17), 'gewicht' => 5,
                ];
            }
        }

        /* Seiten und Beiträge */
        if (Auth::darf('website.write') || Auth::darf('content.write')) {
            foreach (Tenant::all('pages', '(titel LIKE :q OR slug LIKE :q)', ['q' => $q], 'titel', 4) as $s) {
                $treffer[] = [
                    'titel' => (string) $s['titel'],
                    'unter' => '/' . $s['slug'] . ' · ' . ((string) $s['status'] === 'veroeffentlicht' ? 'veröffentlicht' : 'Entwurf'),
                    'url' => App::url('/app/seite.php?id=' . (int) $s['id']),
                    'gruppe' => 'Seiten', 'icon' => Icon::svg('website', 17),
                    'gewicht' => self::gewicht((string) $s['titel'], $begriff) + 4,
                ];
            }
            foreach (Tenant::all('posts', '(titel LIKE :q OR auszug LIKE :q OR text LIKE :q)', ['q' => $q], 'id DESC', 4) as $b) {
                $treffer[] = [
                    'titel' => (string) $b['titel'],
                    'unter' => 'Beitrag · ' . Util::datum((string) ($b['veroeffentlicht'] ?: $b['erstellt'])),
                    'url' => App::url('/app/beitrag.php?id=' . (int) $b['id']),
                    'gruppe' => 'Beiträge', 'icon' => Icon::svg('content', 17), 'gewicht' => 4,
                ];
            }
        }

        /* Gutscheine */
        if (Auth::darf('products.view')) {
            foreach (Tenant::all('vouchers', 'code LIKE :q OR empfaenger LIKE :q', ['q' => $q], 'id DESC', 3) as $g) {
                $treffer[] = [
                    'titel' => 'Gutschein ' . $g['code'],
                    'unter' => Util::geld((int) $g['rest_cent']) . ' Restguthaben · ' . (string) $g['status'],
                    'url' => App::url('/app/gutscheine.php?id=' . (int) $g['id']),
                    'gruppe' => 'Gutscheine', 'icon' => Icon::svg('gift', 17), 'gewicht' => 6,
                ];
            }
        }

        usort($treffer, static fn($a, $b) => $b['gewicht'] <=> $a['gewicht']);
        return array_slice($treffer, 0, $limit);
    }

    /** Wortanfang schlägt Teiltreffer – sonst steht „Bergmann“ vor „Berg“. */
    private static function gewicht(string $text, string $begriff): int
    {
        $t = mb_strtolower($text);
        $b = mb_strtolower($begriff);
        if ($t === $b) {
            return 60;
        }
        if (str_starts_with($t, $b)) {
            return 45;
        }
        if (str_contains($t, ' ' . $b)) {
            return 35;
        }
        if (str_contains($t, $b)) {
            return 20;
        }
        return 5;
    }
}
