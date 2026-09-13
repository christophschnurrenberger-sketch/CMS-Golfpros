<?php
/**
 * Training – Trainingspläne, Übungsbibliothek, Leistungsdaten.
 *
 * Ein Plan besteht aus Positionen, nicht aus Verweisen auf Übungen: Titel,
 * Dauer und Wiederholungen werden beim Anlegen kopiert. Passt der Pro eine
 * Übung später in der Bibliothek an, ändert sich nicht rückwirkend, was ein
 * Kunde vergangene Woche trainiert hat.
 */
final class Training
{
    public const KATEGORIEN = [
        'putting'   => 'Putten',
        'chipping'  => 'Chippen',
        'pitching'  => 'Pitchen',
        'bunker'    => 'Bunker',
        'eisen'     => 'Eisen',
        'holz'      => 'Hölzer & Driver',
        'kurzspiel' => 'Kurzspiel',
        'mental'    => 'Mental',
        'fitness'   => 'Golffitness',
        'regeln'    => 'Regeln & Etikette',
        'platz'     => 'Platztraining',
        'allgemein' => 'Allgemein',
    ];

    public const SCHWIERIGKEIT = ['leicht' => 'Leicht', 'mittel' => 'Mittel', 'schwer' => 'Schwer'];

    /* ------------------------------------------------------ Bibliothek - */

    public static function uebungen(array $f = []): array
    {
        $wo = [];
        $p  = [];
        if (!empty($f['kategorie'])) {
            $wo[] = 'kategorie = :kat';
            $p['kat'] = $f['kategorie'];
        }
        if (!empty($f['suche'])) {
            $wo[] = '(titel LIKE :q OR beschreibung LIKE :q)';
            $p['q'] = '%' . $f['suche'] . '%';
        }
        if (!empty($f['schwierigkeit'])) {
            $wo[] = 'schwierigkeit = :s';
            $p['s'] = $f['schwierigkeit'];
        }
        return Tenant::all('exercises', implode(' AND ', $wo), $p, 'kategorie, titel');
    }

    public static function uebungSpeichern(array $daten, int $id = 0): int
    {
        $satz = array_intersect_key($daten, array_flip([
            'titel', 'beschreibung', 'kategorie', 'video_url', 'bild', 'dauer_min',
            'wiederholungen', 'ziel', 'schwierigkeit', 'ausruestung',
        ]));
        if ($id > 0) {
            Tenant::update('exercises', $id, $satz);
            return $id;
        }
        return Tenant::insert('exercises', $satz);
    }

    /* ---------------------------------------------------------- Pläne -- */

    public static function planSpeichern(array $daten, int $id = 0): int
    {
        $satz = array_intersect_key($daten, array_flip([
            'name', 'beschreibung', 'customer_id', 'vorlage', 'wochen', 'einheiten_woche',
            'ziel', 'niveau', 'status', 'quelle', 'start',
        ]));
        if ($id > 0) {
            Tenant::update('training_plans', $id, $satz);
            Audit::schreiben('geaendert', 'training_plan', $id, (string) ($satz['name'] ?? ''));
            return $id;
        }
        $satz['user_id'] = Auth::id();
        $neu = Tenant::insert('training_plans', $satz);
        Audit::schreiben('erstellt', 'training_plan', $neu, (string) ($satz['name'] ?? ''));
        return $neu;
    }

    public static function positionHinzufuegen(int $planId, array $daten): int
    {
        $uebung = (int) ($daten['exercise_id'] ?? 0) > 0
            ? Tenant::find('exercises', (int) $daten['exercise_id'])
            : null;

        $position = Tenant::count('plan_items', 'plan_id = :p AND woche = :w AND einheit = :e', [
            'p' => $planId, 'w' => (int) ($daten['woche'] ?? 1), 'e' => (int) ($daten['einheit'] ?? 1),
        ]);

        return Tenant::insert('plan_items', [
            'plan_id'        => $planId,
            'exercise_id'    => (int) ($daten['exercise_id'] ?? 0),
            'woche'          => (int) ($daten['woche'] ?? 1),
            'einheit'        => (int) ($daten['einheit'] ?? 1),
            'titel'          => (string) ($daten['titel'] ?? ($uebung['titel'] ?? 'Übung')),
            'notiz'          => (string) ($daten['notiz'] ?? ($uebung['beschreibung'] ?? '')),
            'dauer_min'      => (int) ($daten['dauer_min'] ?? ($uebung['dauer_min'] ?? 15)),
            'wiederholungen' => (string) ($daten['wiederholungen'] ?? ($uebung['wiederholungen'] ?? '')),
            'position'       => $position,
        ]);
    }

    /** @return array<int,array<int,array<int,array<string,mixed>>>> [Woche][Einheit][] */
    public static function planNachWochen(int $planId): array
    {
        $aufbau = [];
        foreach (Tenant::all('plan_items', 'plan_id = :p', ['p' => $planId], 'woche, einheit, position, id') as $z) {
            $aufbau[(int) $z['woche']][(int) $z['einheit']][] = $z;
        }
        return $aufbau;
    }

    public static function planDauer(int $planId): int
    {
        return Tenant::sum('plan_items', 'dauer_min', 'plan_id = :p', ['p' => $planId]);
    }

    public static function zuweisen(int $planId, int $kundeId, string $start = ''): int
    {
        $vorhanden = Tenant::one('plan_assignments', 'plan_id = :p AND customer_id = :k',
            ['p' => $planId, 'k' => $kundeId]);
        if ($vorhanden) {
            return (int) $vorhanden['id'];
        }
        $id = Tenant::insert('plan_assignments', [
            'plan_id'     => $planId,
            'customer_id' => $kundeId,
            'start'       => $start ?: Util::heute(),
            'status'      => 'aktiv',
        ]);
        Tenant::update('training_plans', $planId, ['status' => 'aktiv']);

        $plan  = Tenant::find('training_plans', $planId);
        $kunde = Tenant::find('customers', $kundeId);
        if ($kunde && (string) $kunde['email'] !== '') {
            Mail::anKunden($kunde, 'Dein neuer Trainingsplan',
                "Hallo " . $kunde['vorname'] . ",\n\n"
                . "ich habe dir einen Trainingsplan zusammengestellt: \"" . ($plan['name'] ?? '') . "\".\n\n"
                . "Du findest ihn jederzeit in deinem Bereich – mit allen Übungen, Videos und Zielen.\n\n"
                . "Viel Freude beim Üben!",
                ['knopf_text' => 'Trainingsplan ansehen', 'knopf_url' => Customers::portalLink($kunde)]);
        }
        Audit::schreiben('geaendert', 'training_plan', $planId, 'Zugewiesen an ' . Customers::nameVonId($kundeId));
        return $id;
    }

    public static function fortschritt(int $planId, int $kundeId): int
    {
        $gesamt = Tenant::count('plan_items', 'plan_id = :p', ['p' => $planId]);
        if ($gesamt === 0) {
            return 0;
        }
        $fertig = Tenant::count('plan_items', 'plan_id = :p AND erledigt IS NOT NULL', ['p' => $planId]);
        return (int) round($fertig / $gesamt * 100);
    }

    /* ---------------------------------------------------- Leistungsdaten */

    public const METRIKEN = [
        'hcp'              => ['Handicap', '', 1],
        'score'            => ['Score', 'Schläge', 0],
        'fairways'         => ['Fairways getroffen', 'von 14', 0],
        'gir'              => ['Grüns in Regulation', 'von 18', 0],
        'putts'            => ['Putts', 'pro Runde', 0],
        'penalties'        => ['Strafschläge', '', 0],
        'driving_distance' => ['Driving Distance', 'm', 0],
        'carry'            => ['Carry', 'm', 0],
        'club_speed'       => ['Schlägerkopfgeschwindigkeit', 'mph', 0],
        'ball_speed'       => ['Ballgeschwindigkeit', 'mph', 0],
        'scrambling'       => ['Scrambling', '%', 0],
        'up_and_down'      => ['Up & Down', '%', 0],
    ];

    public static function leistungSpeichern(int $kundeId, array $daten): int
    {
        $satz = ['customer_id' => $kundeId, 'datum' => (string) ($daten['datum'] ?? Util::heute())];
        foreach (array_keys(self::METRIKEN) as $feld) {
            if (isset($daten[$feld]) && $daten[$feld] !== '') {
                $satz[$feld] = $feld === 'hcp' ? (string) $daten[$feld] : (int) $daten[$feld];
            }
        }
        $satz['platz']  = (string) ($daten['platz'] ?? '');
        $satz['notiz']  = (string) ($daten['notiz'] ?? '');
        $satz['quelle'] = (string) ($daten['quelle'] ?? 'trainer');

        $id = Tenant::insert('performance_entries', $satz);

        if (!empty($satz['hcp'])) {
            Tenant::update('customers', $kundeId, ['hcp' => (string) $satz['hcp']]);
        }
        Customers::aktivitaet($kundeId);
        Gamification::punkte($kundeId, 10, 'Runde erfasst');
        return $id;
    }

    /**
     * Verlauf einer Kennzahl. Leere Werte werden übersprungen statt als
     * Null gezeichnet – eine Kurve, die auf Null fällt, weil an dem Tag
     * niemand gemessen hat, ist eine Falschaussage.
     *
     * @return array{labels:string[],werte:float[]}
     */
    public static function verlauf(int $kundeId, string $metrik, int $limit = 24): array
    {
        $zeilen = Tenant::all('performance_entries',
            'customer_id = :k AND ' . ($metrik === 'hcp' ? 'hcp != ""' : $metrik . ' > 0'),
            ['k' => $kundeId], 'datum DESC', $limit);
        $zeilen = array_reverse($zeilen);

        $labels = [];
        $werte  = [];
        foreach ($zeilen as $z) {
            $labels[] = Util::datum((string) $z['datum'], false);
            $werte[]  = $metrik === 'hcp' ? (float) $z['hcp'] : (float) $z[$metrik];
        }
        return ['labels' => $labels, 'werte' => $werte];
    }

    /** Kennzahl im Vergleich zum Durchschnitt der letzten Einträge. */
    public static function entwicklung(int $kundeId, string $metrik): ?array
    {
        $v = self::verlauf($kundeId, $metrik, 12);
        $n = count($v['werte']);
        if ($n < 2) {
            return null;
        }
        $aktuell = $v['werte'][$n - 1];
        $frueher = array_slice($v['werte'], 0, max(1, (int) floor($n / 2)));
        $schnitt = array_sum($frueher) / count($frueher);
        $besserWennKleiner = in_array($metrik, ['hcp', 'score', 'putts', 'penalties'], true);
        $diff = $aktuell - $schnitt;
        return [
            'aktuell'  => $aktuell,
            'schnitt'  => $schnitt,
            'diff'     => $diff,
            'besser'   => $besserWennKleiner ? $diff < 0 : $diff > 0,
        ];
    }

    /**
     * Die eingebaute Übungsbibliothek. Ein leerer Bereich „Übungen“ wäre
     * für einen Pro wertlos – hier steht, was in jeder Golfschule vorkommt.
     */
    public static function standardUebungen(): array
    {
        return [
            ['Uhrzeigerdrill', 'putting', 'Bälle im Kreis um das Loch legen, Abstand 1 Meter. Alle nacheinander lochen. Bei einem Fehlversuch von vorn beginnen.', 15, '12 Bälle', 'Sicherheit auf kurzen Putts', 'leicht'],
            ['Torputten', 'putting', 'Zwei Tees eine Schlägerkopfbreite auseinander, 30 cm vor dem Ball. Durchputten, ohne die Tees zu berühren.', 10, '20 Putts', 'Gerader Schlägerweg', 'leicht'],
            ['Leiterdrill Distanz', 'putting', 'Drei Ziele in 3, 6 und 9 Metern. Je drei Bälle, Ziel ist der Stopp innerhalb einer Schlägerlänge.', 20, '3 × 3 Bälle', 'Längenkontrolle', 'mittel'],
            ['Landepunkt-Chippen', 'chipping', 'Ein Handtuch als Landepunkt auslegen. Nicht auf die Fahne spielen, sondern auf den Landepunkt.', 20, '20 Bälle', 'Kontrolle über den ersten Bodenkontakt', 'leicht'],
            ['Drei Schläger, ein Ziel', 'chipping', 'Denselben Chip mit Pitching-, Eisen 9 und Eisen 7 spielen. Rollverhalten vergleichen.', 20, '15 Bälle', 'Schlägerwahl um das Grün', 'mittel'],
            ['Uhrzeitsystem Pitchen', 'pitching', 'Rückschwung auf 7:30, 9:00 und 10:30 Uhr. Die Weiten notieren und merken.', 25, '30 Bälle', 'Reproduzierbare Teilschwünge', 'mittel'],
            ['Bunker: Linie im Sand', 'bunker', 'Eine Linie in den Sand ziehen und ohne Ball treffen. Der Sand soll immer an derselben Stelle beginnen.', 15, '20 Schwünge', 'Eintauchpunkt', 'mittel'],
            ['Bunker mit offenem Blatt', 'bunker', 'Schlagfläche öffnen, Stand leicht offen, entlang der Fußlinie schwingen.', 20, '15 Bälle', 'Höhe und Stopp aus dem Sand', 'schwer'],
            ['Tempo 3:1', 'eisen', 'Rückschwung drei Zähleinheiten, Durchschwung eine. Mit Metronom oder laut mitzählen.', 15, '25 Bälle', 'Gleichmäßiges Tempo', 'leicht'],
            ['Divot nach dem Ball', 'eisen', 'Tee 5 cm vor dem Ball setzen. Der Divot soll nach dem Ball beginnen.', 20, '20 Bälle', 'Ball zuerst treffen', 'mittel'],
            ['Fahnen-Ziehen', 'eisen', 'Neun Schläge auf neun verschiedene Ziele – wie auf dem Platz, nie zweimal dasselbe.', 25, '9 Bälle', 'Übertrag auf den Platz', 'mittel'],
            ['Rhythmus mit Füßen zusammen', 'holz', 'Halbe Schwünge mit geschlossenem Stand. Gleichgewicht halten bis der Ball gelandet ist.', 15, '20 Bälle', 'Balance im Driver-Schwung', 'mittel'],
            ['Speed-Training', 'holz', 'Drei Schwünge ohne Ball mit maximaler Geschwindigkeit, dann ein Ball mit 80 Prozent.', 20, '5 Durchgänge', 'Schlägerkopfgeschwindigkeit', 'schwer'],
            ['Der eine Ball', 'platz', 'Neun Löcher, ein Ball, kein zweiter Versuch. Score ehrlich notieren.', 120, '9 Löcher', 'Scoring unter Druck', 'mittel'],
            ['Up-and-Down-Runde', 'kurzspiel', 'Neun Bälle um das Übungsgrün verteilen. Jeder Ball muss in zwei Schlägen versenkt werden.', 30, '9 Bälle', 'Scrambling-Quote', 'schwer'],
            ['Routine unter Druck', 'mental', 'Vor jedem Schlag dieselbe Abfolge: Ziel, Probeschwung, Ansprechen, Schlag. Ohne Ausnahme.', 20, '20 Bälle', 'Feste Pre-Shot-Routine', 'leicht'],
            ['Atmen vor dem Putt', 'mental', 'Vor jedem Putt zweimal tief ein- und ausatmen. Herzschlag senken, dann erst ansprechen.', 10, '15 Putts', 'Ruhe auf dem Grün', 'leicht'],
            ['Rotationsmobilität', 'fitness', 'Im Sitzen mit Stock über den Schultern nach links und rechts drehen, Hüfte ruhig halten.', 10, '3 × 12', 'Beweglichkeit der Brustwirbelsäule', 'leicht'],
            ['Einbeinstand', 'fitness', 'Auf einem Bein stehen, Augen schließen, 30 Sekunden halten. Seite wechseln.', 8, '3 × 30 Sek', 'Gleichgewicht', 'leicht'],
            ['Hüftbrücke', 'fitness', 'Rückenlage, Füße aufgestellt, Becken heben und zwei Sekunden halten.', 10, '3 × 15', 'Rumpfstabilität', 'leicht'],
            ['Platzregeln im Gelände', 'regeln', 'Drei typische Situationen durchgehen: Wasserhindernis, unspielbarer Ball, Platzverbot.', 20, '', 'Regelsicherheit für die Platzreife', 'leicht'],
        ];
    }

    public static function standardUebungenAnlegen(): void
    {
        if (Tenant::count('exercises') > 0) {
            return;
        }
        foreach (self::standardUebungen() as [$titel, $kat, $text, $dauer, $wdh, $ziel, $stufe]) {
            Tenant::insert('exercises', [
                'titel' => $titel, 'kategorie' => $kat, 'beschreibung' => $text,
                'dauer_min' => $dauer, 'wiederholungen' => $wdh, 'ziel' => $ziel,
                'schwierigkeit' => $stufe,
            ]);
        }
    }
}
