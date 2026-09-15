<?php
/**
 * Courses – das Kurssystem (LMS).
 *
 * Kurs → Module → Lektionen. Der Fortschritt hängt an der Einschreibung,
 * nicht am Kunden: Wer denselben Kurs ein zweites Mal bucht, fängt nicht
 * bei 60 Prozent an.
 */
final class Courses
{
    public const LEKTIONSARTEN = [
        'video'   => ['Video', 'play'],
        'text'    => ['Text', 'content'],
        'pdf'     => ['PDF', 'invoices'],
        'quiz'    => ['Quiz', 'help'],
        'aufgabe' => ['Aufgabe', 'check'],
    ];

    public static function speichern(array $daten, int $id = 0): int
    {
        $satz = array_intersect_key($daten, array_flip([
            'titel', 'kurztext', 'beschreibung', 'bild', 'preis_cent', 'niveau',
            'zertifikat', 'status',
        ]));
        if (isset($daten['titel'])) {
            $satz['slug'] = Util::slug((string) $daten['titel']);
        }
        if ($id > 0) {
            Tenant::update('courses', $id, $satz);
            return $id;
        }
        $neu = Tenant::insert('courses', $satz);
        Audit::schreiben('erstellt', 'course', $neu, (string) ($satz['titel'] ?? ''));
        return $neu;
    }

    /** @return array<int,array{modul:array<string,mixed>,lektionen:array}> */
    public static function aufbau(int $courseId): array
    {
        $module = Tenant::all('course_modules', 'course_id = :c', ['c' => $courseId], 'position, id');
        $lektionen = [];
        foreach (Tenant::all('course_lessons', 'course_id = :c', ['c' => $courseId], 'position, id') as $l) {
            $lektionen[(int) $l['module_id']][] = $l;
        }
        $aufbau = [];
        foreach ($module as $m) {
            $aufbau[] = ['modul' => $m, 'lektionen' => $lektionen[(int) $m['id']] ?? []];
        }
        if (isset($lektionen[0])) {
            $aufbau[] = ['modul' => ['id' => 0, 'titel' => 'Ohne Modul'], 'lektionen' => $lektionen[0]];
        }
        return $aufbau;
    }

    public static function dauer(int $courseId): int
    {
        return Tenant::sum('course_lessons', 'dauer_min', 'course_id = :c', ['c' => $courseId]);
    }

    public static function lektionenAnzahl(int $courseId): int
    {
        return Tenant::count('course_lessons', 'course_id = :c', ['c' => $courseId]);
    }

    public static function einschreiben(int $courseId, int $kundeId, int $orderId = 0): int
    {
        $vorhanden = Tenant::one('course_enrollments', 'course_id = :c AND customer_id = :k',
            ['c' => $courseId, 'k' => $kundeId]);
        if ($vorhanden) {
            return (int) $vorhanden['id'];
        }
        $id = Tenant::insert('course_enrollments', [
            'course_id' => $courseId, 'customer_id' => $kundeId, 'order_id' => $orderId,
            'begonnen' => null, 'fortschritt' => 0,
        ]);
        $kurs  = Tenant::find('courses', $courseId);
        $kunde = Tenant::find('customers', $kundeId);
        if ($kunde && (string) $kunde['email'] !== '') {
            Mail::anKunden($kunde, 'Dein Kurs ist freigeschaltet',
                "Hallo " . $kunde['vorname'] . ",\n\n"
                . "der Kurs \"" . ($kurs['titel'] ?? '') . "\" steht ab sofort für dich bereit.\n\n"
                . "Du kannst jederzeit starten und in deinem Tempo weitermachen.",
                ['knopf_text' => 'Kurs starten', 'knopf_url' => Customers::zugangLink($kunde)]);
        }
        return $id;
    }

    public static function lektionAbschliessen(int $enrollmentId, int $lessonId, int $punkte = 0): void
    {
        $schon = Tenant::count('lesson_progress', 'enrollment_id = :e AND lesson_id = :l',
            ['e' => $enrollmentId, 'l' => $lessonId]);
        if ($schon === 0) {
            Tenant::insert('lesson_progress', [
                'enrollment_id' => $enrollmentId, 'lesson_id' => $lessonId,
                'punkte' => $punkte, 'abgeschlossen' => Util::jetzt(),
            ]);
        }
        self::fortschrittBerechnen($enrollmentId);
    }

    public static function fortschrittBerechnen(int $enrollmentId): int
    {
        $e = Tenant::find('course_enrollments', $enrollmentId);
        if (!$e) {
            return 0;
        }
        $gesamt = self::lektionenAnzahl((int) $e['course_id']);
        if ($gesamt === 0) {
            return 0;
        }
        $fertig = Tenant::count('lesson_progress', 'enrollment_id = :e', ['e' => $enrollmentId]);
        $prozent = (int) round($fertig / $gesamt * 100);

        $satz = ['fortschritt' => $prozent];
        if ($e['begonnen'] === null) {
            $satz['begonnen'] = Util::jetzt();
        }
        if ($prozent >= 100 && $e['abgeschlossen'] === null) {
            $satz['abgeschlossen'] = Util::jetzt();
            $kurs = Tenant::find('courses', (int) $e['course_id']);
            if ($kurs && (int) $kurs['zertifikat'] === 1) {
                $satz['zertifikat_code'] = 'Z-' . Util::code(10);
            }
            Gamification::punkte((int) $e['customer_id'], 200, 'Kurs abgeschlossen');
            Notify::senden('customer', 'Kurs abgeschlossen',
                Customers::nameVonId((int) $e['customer_id']) . ' hat einen Kurs beendet.',
                '/app/kunde.php?id=' . (int) $e['customer_id']);
        }
        Tenant::update('course_enrollments', $enrollmentId, $satz);
        return $prozent;
    }

    /** @return array<string,mixed> Kennzahlen für die Kursübersicht */
    public static function kennzahlen(int $courseId): array
    {
        $teilnehmer = Tenant::count('course_enrollments', 'course_id = :c', ['c' => $courseId]);
        $fertig = Tenant::count('course_enrollments', 'course_id = :c AND abgeschlossen IS NOT NULL', ['c' => $courseId]);
        $schnitt = $teilnehmer > 0
            ? (int) round(Tenant::sum('course_enrollments', 'fortschritt', 'course_id = :c', ['c' => $courseId]) / $teilnehmer)
            : 0;
        return [
            'teilnehmer' => $teilnehmer,
            'abgeschlossen' => $fertig,
            'fortschritt' => $schnitt,
            'quote' => $teilnehmer > 0 ? (int) round($fertig / $teilnehmer * 100) : 0,
        ];
    }
}
