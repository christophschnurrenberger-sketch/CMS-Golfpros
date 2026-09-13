<?php
/**
 * Customers – die Kundenakte.
 *
 * Der Kunde ist der Mittelpunkt des Systems: An ihm hängen Termine,
 * Zahlungen, Pakete, Trainingspläne, Videos, Kurse und Nachrichten. Deshalb
 * liegt hier auch der Gesundheitswert – er ist keine Spielerei, sondern die
 * Antwort auf die Frage, die sich jeder selbstständige Pro morgens stellt:
 * *Wen sollte ich heute anrufen?*
 */
final class Customers
{
    /* ---------------------------------------------------------- Lesen -- */

    public static function name(array $kunde): string
    {
        return trim(((string) $kunde['vorname']) . ' ' . ((string) $kunde['nachname'])) ?: 'Ohne Namen';
    }

    public static function nameVonId(int $id): string
    {
        static $karte = [];
        if (!isset($karte[$id])) {
            $k = Tenant::find('customers', $id);
            $karte[$id] = $k ? self::name($k) : '—';
        }
        return $karte[$id];
    }

    /**
     * Gefilterte Liste.
     *
     * @param array{suche?:string,status?:string,segment?:int,tag?:string,trainer?:int,
     *              sortierung?:string,limit?:int,versatz?:int} $f
     * @return array<int,array<string,mixed>>
     */
    public static function liste(array $f = []): array
    {
        [$wo, $p] = self::bedingung($f);
        $sortierungen = [
            'name'     => 'nachname, vorname',
            'neu'      => 'id DESC',
            'aktiv'    => 'letzte_aktivitaet DESC',
            'score'    => 'health_score DESC',
            'hcp'      => 'CAST(hcp AS REAL), nachname',
        ];
        $sort = $sortierungen[$f['sortierung'] ?? 'name'] ?? $sortierungen['name'];
        return Tenant::all('customers', $wo, $p, $sort, (int) ($f['limit'] ?? 0), (int) ($f['versatz'] ?? 0));
    }

    public static function anzahl(array $f = []): int
    {
        [$wo, $p] = self::bedingung($f);
        return Tenant::count('customers', $wo, $p);
    }

    /** @return array{0:string,1:array<string,mixed>} */
    private static function bedingung(array $f): array
    {
        $teile = [];
        $p = [];

        if (!empty($f['suche'])) {
            $teile[] = '(vorname LIKE :q OR nachname LIKE :q OR email LIKE :q OR telefon LIKE :q OR heimclub LIKE :q)';
            $p['q'] = '%' . $f['suche'] . '%';
        }
        $status = (string) ($f['status'] ?? 'aktiv');
        if ($status !== 'alle') {
            $teile[] = 'status = :status';
            $p['status'] = $status;
        }
        if (!empty($f['trainer'])) {
            $teile[] = 'trainer_id = :trainer';
            $p['trainer'] = (int) $f['trainer'];
        }
        if (!empty($f['tag'])) {
            $teile[] = 'tags LIKE :tag';
            $p['tag'] = '%"' . $f['tag'] . '"%';
        }
        if (!empty($f['segment'])) {
            $segment = Tenant::find('segments', (int) $f['segment']);
            if ($segment) {
                [$sWo, $sP] = Segments::bedingung(Util::ausJson((string) $segment['regeln']));
                if ($sWo !== '') {
                    $teile[] = '(' . $sWo . ')';
                    $p += $sP;
                }
            }
        }
        return [implode(' AND ', $teile), $p];
    }

    /* --------------------------------------------------------- Schreiben */

    public static function speichern(array $daten, int $id = 0): int
    {
        $erlaubt = [
            'vorname', 'nachname', 'email', 'telefon', 'geburtstag', 'strasse', 'plz', 'ort', 'land',
            'hcp', 'heimclub', 'ziele', 'dominante_hand', 'status', 'quelle', 'trainer_id',
            'location_id', 'tags', 'felder', 'notiz', 'newsletter',
        ];
        $satz = array_intersect_key($daten, array_flip($erlaubt));

        /*
         * Das Handicap kommt aus Formularen mit Komma herein und muss mit
         * Punkt in die Datenbank: Nur so vergleicht SQL richtig und nur so
         * liest PHP die Nachkommastelle. Formatiert wird erst bei der
         * Ausgabe, über Util::hcp().
         */
        if (array_key_exists('hcp', $satz)) {
            $satz['hcp'] = Util::hcpNormal((string) $satz['hcp']);
        }

        if ($id > 0) {
            Tenant::update('customers', $id, $satz);
            Audit::schreiben('geaendert', 'customer', $id, self::nameVonId($id));
            return $id;
        }

        $satz['portal_token']      = Util::token(16);
        $satz['letzte_aktivitaet'] = Util::jetzt();
        $satz['health_score']      = 60;   // Neukunden starten wohlwollend
        $neu = Tenant::insert('customers', $satz);

        Audit::schreiben('erstellt', 'customer', $neu, trim(($satz['vorname'] ?? '') . ' ' . ($satz['nachname'] ?? '')));
        Automations::ausloesen('customer_created', ['customer_id' => $neu]);
        Notify::senden('customer', 'Neuer Kunde: ' . trim(($satz['vorname'] ?? '') . ' ' . ($satz['nachname'] ?? '')),
            'Angelegt über ' . ($satz['quelle'] ?? 'das Backend') . '.', '/app/kunde.php?id=' . $neu);

        return $neu;
    }

    public static function aktivitaet(int $kundeId): void
    {
        Tenant::update('customers', $kundeId, ['letzte_aktivitaet' => Util::jetzt()]);
    }

    /* ------------------------------------------------------------ Tags -- */

    /** @return string[] */
    public static function tags(array $kunde): array
    {
        return Util::ausJson((string) ($kunde['tags'] ?? ''), []);
    }

    public static function tagsSetzen(int $kundeId, array $tags): void
    {
        $tags = array_values(array_unique(array_filter(array_map('trim', $tags))));
        Tenant::update('customers', $kundeId, ['tags' => Util::json($tags)]);
        foreach ($tags as $name) {
            if (Tenant::count('tags', 'name = :n', ['n' => $name]) === 0) {
                Tenant::insert('tags', ['name' => $name, 'farbe' => Util::avatarFarbe($name)]);
            }
        }
    }

    /* ------------------------------------------------ Gesundheitswert -- */

    /**
     * 0–100 aus fünf Bestandteilen. Die Gewichtung ist bewusst offengelegt,
     * damit der Pro versteht, warum jemand „gefährdet“ heißt – eine Zahl,
     * die niemand erklären kann, wird nicht benutzt.
     *
     * @return array{score:int,teile:array<int,array{name:string,wert:int,max:int,text:string}>}
     */
    public static function scoreBerechnen(int $kundeId): array
    {
        $teile = [];

        /* 1. Wie lange ist der letzte Termin her? (max 30) */
        $letzte = (string) DB::value(
            "SELECT MAX(start) FROM bookings WHERE workspace_id = :w AND customer_id = :k
             AND status IN ('bestaetigt','erschienen') AND start <= :jetzt",
            ['w' => Tenant::id(), 'k' => $kundeId, 'jetzt' => Util::jetzt()],
            ''
        );
        $tage = $letzte !== '' ? Util::tageSeit($letzte) : 999;
        $wert = $tage <= 14 ? 30 : ($tage <= 30 ? 25 : ($tage <= 60 ? 17 : ($tage <= 90 ? 9 : ($tage <= 180 ? 4 : 0))));
        $teile[] = ['name' => 'Letzter Termin', 'wert' => $wert, 'max' => 30,
                    'text' => $letzte !== '' ? 'vor ' . $tage . ' Tagen' : 'noch keiner'];

        /* 2. Wie regelmäßig in den letzten 90 Tagen? (max 25) */
        $anzahl = DB::int(
            "SELECT COUNT(*) FROM bookings WHERE workspace_id = :w AND customer_id = :k
             AND status IN ('bestaetigt','erschienen') AND start >= :seit",
            ['w' => Tenant::id(), 'k' => $kundeId, 'seit' => date('Y-m-d', strtotime('-90 days'))]
        );
        $wert = min(25, $anzahl * 5);
        $teile[] = ['name' => 'Termine (90 Tage)', 'wert' => $wert, 'max' => 25, 'text' => $anzahl . ' Termine'];

        /* 3. Umsatz der letzten zwölf Monate (max 20) */
        $umsatz = Tenant::sum('orders', 'summe_cent',
            'customer_id = :k AND status = "bezahlt" AND erstellt >= :seit',
            ['k' => $kundeId, 'seit' => date('Y-m-d', strtotime('-12 months'))]);
        $wert = (int) min(20, $umsatz / 5000);   // 1000 € = volle Punktzahl
        $teile[] = ['name' => 'Umsatz (12 Monate)', 'wert' => $wert, 'max' => 20, 'text' => Util::geld($umsatz)];

        /* 4. Bewegt sich etwas im Training? (max 15) */
        $plaene = Tenant::count('plan_assignments', "customer_id = :k AND status = 'aktiv'", ['k' => $kundeId]);
        $daten  = Tenant::count('performance_entries', 'customer_id = :k AND datum >= :seit',
            ['k' => $kundeId, 'seit' => date('Y-m-d', strtotime('-90 days'))]);
        $wert = min(15, $plaene * 8 + $daten * 2);
        $teile[] = ['name' => 'Trainingsfortschritt', 'wert' => $wert, 'max' => 15,
                    'text' => $plaene . ' aktiver Plan, ' . $daten . ' Messungen'];

        /* 5. Erreicht man ihn? (max 10) */
        $kontakt = Tenant::count('communications', 'customer_id = :k AND erstellt >= :seit',
            ['k' => $kundeId, 'seit' => date('Y-m-d', strtotime('-60 days'))]);
        $wert = min(10, $kontakt * 3);
        $teile[] = ['name' => 'Kontakt (60 Tage)', 'wert' => $wert, 'max' => 10, 'text' => $kontakt . ' Nachrichten'];

        $score = 0;
        foreach ($teile as $t) {
            $score += $t['wert'];
        }
        return ['score' => (int) Util::klemmen((float) $score, 0, 100), 'teile' => $teile];
    }

    public static function scoreSpeichern(int $kundeId): int
    {
        $ergebnis = self::scoreBerechnen($kundeId);
        Tenant::update('customers', $kundeId, ['health_score' => $ergebnis['score']]);
        return $ergebnis['score'];
    }

    /** Läuft beim Öffnen des Dashboards – hält die Werte ohne Cronjob frisch. */
    public static function scoresAuffrischen(int $maximal = 40): void
    {
        foreach (Tenant::all('customers', "status = 'aktiv'", [], 'letzte_aktivitaet', $maximal) as $k) {
            self::scoreSpeichern((int) $k['id']);
        }
    }

    public static function stufe(int $score): array
    {
        if ($score >= 70) {
            return ['gesund', 'Gesund', 'erfolg'];
        }
        if ($score >= 40) {
            return ['gefaehrdet', 'Gefährdet', 'warnung'];
        }
        return ['inaktiv', 'Inaktiv', 'gefahr'];
    }

    /* ------------------------------------------------------- Kundenakte */

    /** Alles, was die Kundenseite zeigt – in einer Abfragefolge. */
    public static function akte(int $kundeId): array
    {
        $w = ['k' => $kundeId];
        return [
            'buchungen' => Tenant::all('bookings', 'customer_id = :k', $w, 'start DESC', 40),
            'kommend'   => Tenant::all('bookings', "customer_id = :k AND start >= :jetzt AND status != 'abgesagt'",
                            $w + ['jetzt' => Util::jetzt()], 'start', 5),
            'pakete'    => Tenant::all('customer_packages', 'customer_id = :k', $w, 'status, laeuft_ab'),
            'rechnungen'=> Tenant::all('invoices', 'customer_id = :k', $w, 'datum DESC', 20),
            'zahlungen' => Tenant::all('payments', 'customer_id = :k', $w, 'id DESC', 20),
            'plaene'    => DB::all('SELECT p.*, a.fortschritt, a.status AS zuweisung_status
                                    FROM plan_assignments a JOIN training_plans p ON p.id = a.plan_id
                                    WHERE a.workspace_id = :ws AND a.customer_id = :k ORDER BY a.id DESC',
                                    ['ws' => Tenant::id(), 'k' => $kundeId]),
            'videos'    => Tenant::all('videos', 'customer_id = :k', $w, 'id DESC', 12),
            'analysen'  => Tenant::all('video_analyses', 'customer_id = :k', $w, 'id DESC', 12),
            'kurse'     => DB::all('SELECT e.*, c.titel FROM course_enrollments e JOIN courses c ON c.id = e.course_id
                                    WHERE e.workspace_id = :ws AND e.customer_id = :k ORDER BY e.id DESC',
                                    ['ws' => Tenant::id(), 'k' => $kundeId]),
            'gutscheine'=> Tenant::all('vouchers', 'customer_id = :k', $w, 'id DESC', 10),
            'notizen'   => Tenant::all('customer_notes', 'customer_id = :k', $w, 'angeheftet DESC, id DESC', 30),
            'nachrichten'=> Tenant::all('communications', 'customer_id = :k', $w, 'id DESC', 30),
            'leistung'  => Tenant::all('performance_entries', 'customer_id = :k', $w, 'datum DESC', 60),
            'dokumente' => Tenant::all('documents', 'customer_id = :k', $w, 'id DESC', 20),
            'events'    => DB::all('SELECT r.*, e.titel, e.start FROM event_registrations r JOIN events e ON e.id = r.event_id
                                    WHERE r.workspace_id = :ws AND r.customer_id = :k ORDER BY e.start DESC LIMIT 10',
                                    ['ws' => Tenant::id(), 'k' => $kundeId]),
        ];
    }

    /** Zahlen für den Kopf der Kundenakte. */
    public static function kennzahlen(int $kundeId): array
    {
        return [
            'umsatz'     => Tenant::sum('orders', 'summe_cent', 'customer_id = :k AND status = "bezahlt"', ['k' => $kundeId]),
            'termine'    => Tenant::count('bookings', "customer_id = :k AND status IN ('bestaetigt','erschienen')", ['k' => $kundeId]),
            'offen'      => Tenant::sum('invoices', 'summe_cent - bezahlt_cent',
                            'customer_id = :k AND status IN ("offen","ueberfaellig")', ['k' => $kundeId]),
            'einheiten'  => Tenant::sum('customer_packages', 'einheiten_gesamt - einheiten_genutzt',
                            'customer_id = :k AND status = "aktiv"', ['k' => $kundeId]),
            'seit'       => (string) DB::value('SELECT erstellt FROM customers WHERE id = :k AND workspace_id = :w',
                            ['k' => $kundeId, 'w' => Tenant::id()], ''),
        ];
    }

    /* ------------------------------------------------------ Portalzugang */

    public static function portalLink(array $kunde): string
    {
        return App::absolut('/portal/?t=' . rawurlencode((string) $kunde['portal_token']));
    }

    /* -------------------------------------------------------- Löschen -- */

    /**
     * Vollständige Löschung nach Artikel 17 DSGVO. Bezahlte Rechnungen
     * bleiben – für sie gilt die handelsrechtliche Aufbewahrungsfrist, die
     * das Löschrecht an dieser Stelle überwiegt. Der Bezug zum Kunden wird
     * dabei gekappt und der Name in der Rechnung eingefroren; der Beleg
     * bleibt gültig, die Akte verschwindet.
     */
    public static function loeschen(int $kundeId): void
    {
        $kunde = Tenant::find('customers', $kundeId);
        if (!$kunde) {
            return;
        }
        DB::transaktion(function () use ($kundeId, $kunde) {
            foreach (['bookings', 'customer_packages', 'customer_notes', 'communications',
                      'performance_entries', 'videos', 'video_analyses', 'plan_assignments',
                      'course_enrollments', 'event_registrations', 'documents', 'consents',
                      'customer_badges', 'customer_gamification', 'challenge_participants',
                      'waitlist', 'booking_participants'] as $tabelle) {
                Tenant::deleteWhere($tabelle, 'customer_id = :k', ['k' => $kundeId]);
            }
            Tenant::updateWhere('invoices', ['customer_id' => 0], 'customer_id = :k', ['k' => $kundeId]);
            Tenant::updateWhere('orders', ['customer_id' => 0, 'email' => '', 'name' => 'Gelöschter Kunde'],
                'customer_id = :k', ['k' => $kundeId]);
            Tenant::updateWhere('payments', ['customer_id' => 0], 'customer_id = :k', ['k' => $kundeId]);
            Tenant::delete('customers', $kundeId);
        });
        Audit::schreiben('geloescht', 'customer', $kundeId, self::name($kunde) . ' (DSGVO-Löschung)');
    }

    /** Vollständiger Datensatz zum Export – Artikel 20 DSGVO. */
    public static function export(int $kundeId): array
    {
        $kunde = Tenant::find('customers', $kundeId);
        if (!$kunde) {
            return [];
        }
        unset($kunde['portal_passwort'], $kunde['portal_token']);
        return ['stammdaten' => $kunde] + self::akte($kundeId);
    }
}
