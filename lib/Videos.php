<?php
/**
 * Videos – Schwungvideos und Analysen.
 *
 * Die Analyse ist vom Video getrennt: Zu einem Video können mehrere
 * Analysen gehören (Erstaufnahme, sechs Wochen später), und eine Analyse
 * kann zwei Videos vergleichen. Zeichnungen liegen als Koordinaten im
 * JSON, nicht als gerendertes Bild – so bleiben sie später veränderbar
 * und funktionieren auf jeder Bildschirmgröße.
 *
 * KI-Hinweise und Pro-Analyse stehen in **getrennten Feldern**. Das ist
 * keine technische Feinheit, sondern die inhaltliche Grundregel: Die
 * Einschätzung der KI darf die des Trainers nie ersetzen und muss als
 * solche erkennbar bleiben.
 */
final class Videos
{
    public const ANSICHTEN = [
        'face-on'       => 'Von vorn (Face-on)',
        'down-the-line' => 'Zielline (Down the line)',
        'hinten'        => 'Von hinten',
        'oben'          => 'Von oben',
    ];

    public const WERKZEUGE = [
        'linie'    => ['Linie', 'code'],
        'kreis'    => ['Kreis', 'target'],
        'winkel'   => ['Winkel', 'activity'],
        'rechteck' => ['Rechteck', 'grid'],
        'text'     => ['Text', 'type'],
        'frei'     => ['Freihand', 'edit'],
    ];

    public static function speichern(array $daten, int $id = 0): int
    {
        $satz = array_intersect_key($daten, array_flip([
            'customer_id', 'titel', 'datei', 'vorschau', 'quelle', 'ansicht',
            'schlaeger', 'dauer_sek', 'groesse',
        ]));
        if ($id > 0) {
            Tenant::update('videos', $id, $satz);
            return $id;
        }
        $satz['user_id'] = Auth::id();
        $neu = Tenant::insert('videos', $satz);
        if ((int) ($satz['customer_id'] ?? 0) > 0) {
            Customers::aktivitaet((int) $satz['customer_id']);
        }
        Audit::schreiben('erstellt', 'video', $neu, (string) ($satz['titel'] ?? ''));
        return $neu;
    }

    public static function analyseSpeichern(array $daten, int $id = 0): int
    {
        $satz = array_intersect_key($daten, array_flip([
            'video_id', 'vergleich_video_id', 'customer_id', 'titel', 'zeichnungen',
            'pro_analyse', 'ai_analyse', 'ai_bestaetigt', 'empfehlungen', 'status',
        ]));
        if ($id > 0) {
            Tenant::update('video_analyses', $id, $satz);
            Audit::schreiben('geaendert', 'video_analysis', $id, (string) ($satz['titel'] ?? ''));
            return $id;
        }
        $satz['user_id'] = Auth::id();
        return Tenant::insert('video_analyses', $satz);
    }

    /** Freigeben heißt: Der Kunde sieht sie im Portal und bekommt eine Mail. */
    public static function freigeben(int $id): void
    {
        $a = Tenant::find('video_analyses', $id);
        if (!$a || $a['status'] === 'freigegeben') {
            return;
        }
        Tenant::update('video_analyses', $id, ['status' => 'freigegeben', 'freigegeben' => Util::jetzt()]);

        $kunde = Tenant::find('customers', (int) $a['customer_id']);
        if ($kunde && (string) $kunde['email'] !== '') {
            Mail::anKunden($kunde, 'Deine Schwunganalyse ist fertig',
                "Hallo " . $kunde['vorname'] . ",\n\n"
                . "ich habe mir dein Video angesehen und die Analyse für dich zusammengestellt.\n\n"
                . "Du findest sie mit allen Markierungen und meinen Anmerkungen in deinem Bereich.",
                ['knopf_text' => 'Analyse ansehen', 'knopf_url' => Customers::zugangLink($kunde)]);
        }
        Gamification::punkte((int) $a['customer_id'], 30, 'Analyse erhalten');
        Audit::schreiben('veroeffentlicht', 'video_analysis', $id, 'Für den Kunden freigegeben');
    }

    /**
     * Die eingebaute Hinweisliste der KI-Schicht.
     *
     * Sie ersetzt keine Bildauswertung – ohne hinterlegten KI-Schlüssel
     * liefert sie typische Prüfpunkte zur gewählten Ansicht, die der Pro
     * abhakt oder verwirft. Das ist ehrlicher als eine erfundene
     * Bewegungsanalyse und im Trainingsalltag trotzdem nützlich.
     *
     * @return array<int,array{bereich:string,hinweis:string,pruefen:string}>
     */
    public static function pruefpunkte(string $ansicht): array
    {
        $gemeinsam = [
            ['bereich' => 'Balance', 'hinweis' => 'Gewichtsverlagerung zum Treffmoment',
             'pruefen' => 'Steht das Gewicht im Treffmoment überwiegend auf dem vorderen Fuß?'],
            ['bereich' => 'Tempo', 'hinweis' => 'Verhältnis Rück- zu Durchschwung',
             'pruefen' => 'Etwa 3:1 – wirkt der Rückschwung gehetzt?'],
        ];
        $ansichtsspezifisch = [
            'face-on' => [
                ['bereich' => 'Haltung', 'hinweis' => 'Kopfposition über dem Ball',
                 'pruefen' => 'Wandert der Kopf im Rückschwung seitlich aus?'],
                ['bereich' => 'Rotation', 'hinweis' => 'Hüftdrehung im Durchschwung',
                 'pruefen' => 'Öffnet sich die Hüfte zum Ziel oder bleibt sie stehen?'],
                ['bereich' => 'Körperwinkel', 'hinweis' => 'Seitliche Neigung im Treffmoment',
                 'pruefen' => 'Liegt die Schulterachse leicht nach hinten geneigt?'],
            ],
            'down-the-line' => [
                ['bereich' => 'Haltung', 'hinweis' => 'Wirbelsäulenwinkel im Stand',
                 'pruefen' => 'Bleibt der Winkel vom Ansprechen bis zum Treffmoment erhalten?'],
                ['bereich' => 'Schwungbahn', 'hinweis' => 'Schlägerposition auf halber Höhe',
                 'pruefen' => 'Liegt der Schaft parallel zur Ziellinie?'],
                ['bereich' => 'Rotation', 'hinweis' => 'Schulterdrehung im Rückschwung',
                 'pruefen' => 'Dreht die vordere Schulter hinter den Ball?'],
            ],
        ];
        return array_merge($ansichtsspezifisch[$ansicht] ?? $ansichtsspezifisch['face-on'], $gemeinsam);
    }
}
