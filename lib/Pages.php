<?php
/**
 * Pages – Seiten und Landingpages, als Baum.
 *
 * Eine Seite ist eine Liste von Bausteinen plus SEO-Angaben. Es gibt genau
 * eine Startseite je Workspace; sie lässt sich umziehen, aber nicht löschen –
 * eine Website ohne Startseite ist kaputt, und das soll man nicht aus
 * Versehen erreichen können.
 *
 * **Jede Seite kennt ihre übergeordnete** (`parent_id`, 0 für die oberste
 * Ebene). Daraus entsteht die Navigation: Hauptpunkte nebeneinander,
 * Unterpunkte im Klappmenü darunter.
 *
 * Die Reihenfolge (`position`) gilt **innerhalb einer Ebene**. Zwei Seiten
 * mit derselben Position unter verschiedenen Eltern stören einander nicht,
 * und ein Einrücken muss nicht die halbe Website umnummerieren.
 *
 * Die Startseite steht nicht im Baum: Sie ist die Marke oben links, kein
 * Menüpunkt. Untergeordnete Seiten bekommt sie deshalb auch nicht.
 *
 * Was die Adresse angeht, bleibt der Baum ohne Wirkung: Jede Seite behält
 * ihren eigenen, im ganzen Workspace eindeutigen Slug (`/platzreife`, nicht
 * `/kurse/platzreife`). Verschachtelte Adressen wären schöner, würden aber
 * jede veröffentlichte Adresse ändern, sobald jemand eine Seite einrückt –
 * und damit jeden Link, der von außen darauf zeigt.
 */
final class Pages
{
    /**
     * So viele Ebenen tief geht das Menü.
     *
     * Drei: Hauptpunkt, Unterpunkt, Unter-Unterpunkt. Was tiefer liegt,
     * findet auf dem Telefon niemand mehr – und wer es doch braucht, baut
     * dafür eine Übersichtsseite mit Kacheln.
     */
    public const MAX_TIEFE = 3;

    public static function alle(string $art = ''): array
    {
        $wo = $art !== '' ? 'art = :a' : '';
        return Tenant::all('pages', $wo, $art !== '' ? ['a' => $art] : [], 'startseite DESC, position, titel');
    }

    public static function startseite(): ?array
    {
        return Tenant::one('pages', 'startseite = 1');
    }

    public static function nachSlug(string $slug): ?array
    {
        return Tenant::one('pages', 'slug = :s', ['s' => $slug]);
    }

    /**
     * Die Menüpunkte der obersten Ebene, jeder mit seinen Kindern.
     *
     * @return array<int,array<string,mixed>> je Seite zusätzlich 'kinder'
     */
    public static function menue(): array
    {
        return self::baum(Tenant::all('pages',
            "status = 'veroeffentlicht' AND im_menue = 1 AND startseite = 0",
            [], 'position, titel'));
    }

    /**
     * Alle Seiten als flache Liste – aber in Baumreihenfolge, jede mit
     * ihrer Tiefe.
     *
     * Für Listen und Auswahlfelder: Dort ist eine eingerückte Zeile
     * bequemer zu bauen als eine Verschachtelung, und die Reihenfolge
     * stimmt trotzdem mit dem Baum überein.
     *
     * @param array<int,array<string,mixed>>|null $seiten
     * @return array<int,array<string,mixed>> je Seite zusätzlich 'tiefe'
     */
    public static function flach(?array $seiten = null): array
    {
        $seiten = $seiten ?? self::alle();
        $aus = [];
        $gehen = static function (int $eltern, int $tiefe) use (&$gehen, $seiten, &$aus): void {
            foreach ($seiten as $s) {
                if ((int) ($s['parent_id'] ?? 0) !== $eltern) {
                    continue;
                }
                $s['tiefe'] = $tiefe;
                $aus[] = $s;
                $gehen((int) $s['id'], $tiefe + 1);
            }
        };
        $gehen(0, 0);

        /*
         * Waisen sind keine Fehlermeldung wert, aber verschwinden dürfen
         * sie auch nicht: Wessen Elternteil gelöscht wurde, ohne dass die
         * Kinder hochgerückt sind, steht hier wieder ganz oben.
         */
        $drin = array_column($aus, 'id');
        foreach ($seiten as $s) {
            if (!in_array($s['id'], $drin, true)) {
                $s['tiefe'] = 0;
                $aus[] = $s;
            }
        }
        return $aus;
    }

    /**
     * Aus einer flachen Liste eine verschachtelte machen.
     *
     * @param array<int,array<string,mixed>> $seiten
     * @return array<int,array<string,mixed>>
     */
    public static function baum(array $seiten): array
    {
        $nachEltern = [];
        foreach ($seiten as $s) {
            $nachEltern[(int) ($s['parent_id'] ?? 0)][] = $s;
        }
        $vorhanden = array_map('intval', array_column($seiten, 'id'));

        $bauen = static function (int $eltern, int $tiefe) use (&$bauen, $nachEltern): array {
            if ($tiefe >= self::MAX_TIEFE) {
                return [];
            }
            $zweig = [];
            foreach ($nachEltern[$eltern] ?? [] as $s) {
                $s['kinder'] = $bauen((int) $s['id'], $tiefe + 1);
                $zweig[] = $s;
            }
            return $zweig;
        };

        $oben = $bauen(0, 0);

        /* Ein Kind, dessen Elternteil nicht in dieser Auswahl steht – etwa
           weil die übergeordnete Seite noch Entwurf ist –, hinge sonst in
           der Luft. Es rückt eine Ebene hoch, statt zu verschwinden. */
        foreach ($nachEltern as $eltern => $kinder) {
            if ($eltern === 0 || in_array($eltern, $vorhanden, true)) {
                continue;
            }
            foreach ($kinder as $s) {
                $s['kinder'] = $bauen((int) $s['id'], 1);
                $oben[] = $s;
            }
        }
        return $oben;
    }

    /**
     * Der Weg von oben bis zu dieser Seite, die Seite selbst zuletzt.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function pfad(int $id): array
    {
        $weg = [];
        $gesehen = [];
        while ($id > 0 && !in_array($id, $gesehen, true) && count($weg) <= self::MAX_TIEFE) {
            $gesehen[] = $id;
            $seite = Tenant::find('pages', $id);
            if ($seite === null) {
                break;
            }
            array_unshift($weg, $seite);
            $id = (int) $seite['parent_id'];
        }
        return $weg;
    }

    /**
     * Diese Seite und alles, was unter ihr hängt.
     *
     * @return array<int,int>
     */
    public static function nachkommen(int $id): array
    {
        $ids = [$id];
        $offen = [$id];
        while ($offen !== []) {
            $eltern = array_shift($offen);
            foreach (Tenant::all('pages', 'parent_id = :p', ['p' => $eltern], 'position') as $kind) {
                $kindId = (int) $kind['id'];
                if (!in_array($kindId, $ids, true)) {
                    $ids[] = $kindId;
                    $offen[] = $kindId;
                }
            }
        }
        return $ids;
    }

    /**
     * Wie tief unter der obersten Ebene liegt diese Seite? 0 = ganz oben.
     */
    public static function tiefe(int $id): int
    {
        return max(0, count(self::pfad($id)) - 1);
    }

    /** Wie viele Ebenen umfasst dieser Ast, von hier nach unten? */
    public static function hoehe(int $id): int
    {
        $hoch = 1;
        foreach (Tenant::all('pages', 'parent_id = :p', ['p' => $id]) as $kind) {
            $hoch = max($hoch, 1 + self::hoehe((int) $kind['id']));
        }
        return $hoch;
    }

    /**
     * Seiten, die als übergeordnete in Frage kommen.
     *
     * Draußen bleiben: die Seite selbst und alles unter ihr (sonst hinge
     * der Ast an sich selbst), die Startseite (sie ist die Marke, kein
     * Menüpunkt), Landingpages (die stehen bewusst allein) und alles, was
     * zu tief läge, wenn dieser Ast dort einzöge.
     *
     * @return array<int,array<string,mixed>> mit 'tiefe'
     */
    public static function elternMoeglich(int $id): array
    {
        $tabu = $id > 0 ? self::nachkommen($id) : [];
        $hoch = $id > 0 ? self::hoehe($id) : 1;

        $moeglich = [];
        foreach (self::flach() as $s) {
            if (in_array((int) $s['id'], $tabu, true)
                || (int) $s['startseite'] === 1
                || (string) $s['art'] === 'landingpage') {
                continue;
            }
            /* Ein Ast von zwei Ebenen passt nur dort hinein, wo darunter
               noch zwei Ebenen frei sind. */
            if ((int) $s['tiefe'] + 1 + $hoch > self::MAX_TIEFE) {
                continue;
            }
            $moeglich[] = $s;
        }
        return $moeglich;
    }

    /**
     * Hängt eine Seite unter eine andere – oder nach ganz oben (0).
     *
     * @return array{0:bool,1:string} [gelungen, Grund wenn nicht]
     */
    public static function einordnen(int $id, int $elternId): array
    {
        $seite = Tenant::find('pages', $id);
        if ($seite === null) {
            return [false, 'Diese Seite gibt es nicht.'];
        }
        if ($elternId === 0) {
            Tenant::update('pages', $id, ['parent_id' => 0, 'position' => self::naechstePosition(0)]);
            return [true, ''];
        }
        if (in_array($elternId, self::nachkommen($id), true)) {
            return [false, 'Eine Seite kann nicht unter sich selbst hängen.'];
        }
        $eltern = Tenant::find('pages', $elternId);
        if ($eltern === null) {
            return [false, 'Die übergeordnete Seite gibt es nicht.'];
        }
        if ((int) $eltern['startseite'] === 1) {
            return [false, 'Die Startseite ist die Marke oben links und bekommt keine Unterseiten.'];
        }
        if (self::tiefe($elternId) + 1 + self::hoehe($id) > self::MAX_TIEFE) {
            return [false, 'Tiefer als ' . self::MAX_TIEFE . ' Ebenen wird das Menü unbedienbar.'];
        }
        Tenant::update('pages', $id, [
            'parent_id' => $elternId,
            'position'  => self::naechstePosition($elternId),
        ]);
        return [true, ''];
    }

    /**
     * Eine Seite unter ihren Geschwistern nach oben oder unten schieben.
     *
     * Die Positionen werden dabei neu durchgezählt. Das ist billiger als
     * jedes Mal zu prüfen, ob die alten noch lückenlos sind – und nach
     * einem Import oder einem gelöschten Geschwister sind sie das nie.
     */
    public static function schieben(int $id, int $richtung): bool
    {
        $seite = Tenant::find('pages', $id);
        if ($seite === null) {
            return false;
        }
        $geschwister = Tenant::all('pages', 'parent_id = :p',
            ['p' => (int) $seite['parent_id']], 'position, titel');
        $ids = array_map('intval', array_column($geschwister, 'id'));
        $jetzt = array_search($id, $ids, true);
        if ($jetzt === false) {
            return false;
        }
        $ziel = $jetzt + ($richtung < 0 ? -1 : 1);
        if ($ziel < 0 || $ziel >= count($ids)) {
            return false;
        }
        [$ids[$jetzt], $ids[$ziel]] = [$ids[$ziel], $ids[$jetzt]];
        foreach ($ids as $i => $sId) {
            Tenant::update('pages', $sId, ['position' => $i]);
        }
        return true;
    }

    /**
     * Eine Ebene tiefer: unter die Seite, die direkt darüber steht.
     *
     * Ohne Auswahlfeld, weil es nur ein sinnvolles Ziel gibt. Wer eine
     * Seite woandershin hängen will, nimmt das Feld „Untergeordnet" in den
     * Seiteneinstellungen.
     *
     * @return array{0:bool,1:string}
     */
    public static function einruecken(int $id): array
    {
        $seite = Tenant::find('pages', $id);
        if ($seite === null) {
            return [false, 'Diese Seite gibt es nicht.'];
        }
        $vorheriges = null;
        foreach (Tenant::all('pages', 'parent_id = :p',
                 ['p' => (int) $seite['parent_id']], 'position, titel') as $g) {
            if ((int) $g['id'] === $id) {
                break;
            }
            $vorheriges = $g;
        }
        if ($vorheriges === null) {
            return [false, 'Darüber steht keine Seite, unter die sie rutschen könnte.'];
        }
        return self::einordnen($id, (int) $vorheriges['id']);
    }

    /**
     * Eine Ebene höher: neben die bisherige übergeordnete Seite, direkt
     * hinter sie.
     *
     * @return array{0:bool,1:string}
     */
    public static function ausruecken(int $id): array
    {
        $seite = Tenant::find('pages', $id);
        if ($seite === null) {
            return [false, 'Diese Seite gibt es nicht.'];
        }
        $elternId = (int) $seite['parent_id'];
        if ($elternId === 0) {
            return [false, 'Weiter nach oben geht es nicht – sie steht schon auf der obersten Ebene.'];
        }
        $eltern = Tenant::find('pages', $elternId);
        $grossId = $eltern !== null ? (int) $eltern['parent_id'] : 0;

        Tenant::update('pages', $id, ['parent_id' => $grossId]);

        /* Direkt hinter die bisherige übergeordnete Seite einsortieren –
           dort erwartet man sie, nicht am Ende der Ebene. */
        $ids = [];
        foreach (Tenant::all('pages', 'parent_id = :p', ['p' => $grossId], 'position, titel') as $g) {
            if ((int) $g['id'] === $id) {
                continue;
            }
            $ids[] = (int) $g['id'];
            if ((int) $g['id'] === $elternId) {
                $ids[] = $id;
            }
        }
        if (!in_array($id, $ids, true)) {
            $ids[] = $id;
        }
        foreach ($ids as $i => $sId) {
            Tenant::update('pages', $sId, ['position' => $i]);
        }
        return [true, ''];
    }

    private static function naechstePosition(int $elternId): int
    {
        $letzte = Tenant::one('pages', 'parent_id = :p', ['p' => $elternId], 'position DESC');
        return $letzte === null ? 0 : (int) $letzte['position'] + 1;
    }

    public static function bloecke(array $seite): array
    {
        return Util::ausJson((string) ($seite['bloecke'] ?? ''), []);
    }

    public static function speichern(array $daten, int $id = 0): int
    {
        $satz = array_intersect_key($daten, array_flip([
            'titel', 'bloecke', 'seo', 'im_menue', 'position', 'status', 'art', 'parent_id',
        ]));
        $satz['geaendert'] = Util::jetzt();

        if (isset($daten['slug']) && (string) $daten['slug'] !== '') {
            $satz['slug'] = self::freierSlug(Util::slug((string) $daten['slug']), $id);
        } elseif ($id === 0 && isset($daten['titel'])) {
            $satz['slug'] = self::freierSlug(Util::slug((string) $daten['titel']), 0);
        }

        if ($id > 0) {
            Tenant::update('pages', $id, $satz);
            Audit::schreiben('geaendert', 'page', $id, (string) ($satz['titel'] ?? ''));
            return $id;
        }
        $neu = Tenant::insert('pages', $satz);
        Audit::schreiben('erstellt', 'page', $neu, (string) ($satz['titel'] ?? ''));
        return $neu;
    }

    public static function freierSlug(string $slug, int $ausser = 0): string
    {
        $basis = $slug !== '' ? $slug : 'seite';
        $versuch = $basis;
        $n = 2;
        while (Tenant::count('pages', 'slug = :s AND id != :id', ['s' => $versuch, 'id' => $ausser]) > 0) {
            $versuch = $basis . '-' . $n++;
        }
        return $versuch;
    }

    /**
     * Macht eine Seite zur Startseite.
     *
     * Dabei rückt sie nach ganz oben: Die Startseite ist die Marke oben
     * links, kein Menüpunkt – sie kann nicht unter einer anderen Seite
     * hängen. Ihre bisherigen Unterseiten rücken mit ihr eine Ebene hoch.
     */
    public static function startseiteSetzen(int $id): void
    {
        $vorher = Tenant::find('pages', $id);
        Tenant::updateWhere('pages', ['startseite' => 0], '1=1');
        Tenant::update('pages', $id, [
            'startseite' => 1, 'status' => 'veroeffentlicht', 'parent_id' => 0,
        ]);
        foreach (Tenant::all('pages', 'parent_id = :p', ['p' => $id], 'position') as $kind) {
            Tenant::update('pages', (int) $kind['id'], [
                'parent_id' => (int) ($vorher['parent_id'] ?? 0),
            ]);
        }
    }

    /**
     * Löscht eine Seite – ihre Unterseiten rücken eine Ebene hoch.
     *
     * Mitzulöschen wäre die überraschendere Variante: Wer „Kurse" entfernt,
     * meint selten, dass „Platzreife" und „Abschlagtraining" mit
     * verschwinden sollen. Hochrücken lässt sich rückgängig machen, ein
     * Löschen nicht.
     */
    public static function loeschen(int $id): bool
    {
        $seite = Tenant::find('pages', $id);
        if (!$seite || (int) $seite['startseite'] === 1) {
            return false;
        }
        $kinder = Tenant::all('pages', 'parent_id = :p', ['p' => $id], 'position');
        foreach ($kinder as $kind) {
            Tenant::update('pages', (int) $kind['id'], [
                'parent_id' => (int) $seite['parent_id'],
                'position'  => self::naechstePosition((int) $seite['parent_id']),
            ]);
        }
        Tenant::delete('pages', $id);
        Audit::schreiben('geloescht', 'page', $id, (string) $seite['titel']
            . ($kinder !== [] ? ' · ' . count($kinder) . ' Unterseiten eine Ebene hochgerückt' : ''));
        return true;
    }

    public static function seo(array $seite): array
    {
        return Util::ausJson((string) ($seite['seo'] ?? ''), []) + [
            'titel' => (string) $seite['titel'],
            'beschreibung' => '',
            'bild' => '',
            'index' => true,
        ];
    }

    public static function url(array $seite): string
    {
        $slug = (string) (Tenant::workspace()['slug'] ?? '');
        if ((int) $seite['startseite'] === 1) {
            return App::url('/site.php?w=' . rawurlencode($slug));
        }
        return App::url('/site.php?w=' . rawurlencode($slug) . '&s=' . rawurlencode((string) $seite['slug']));
    }

    /** Conversion-Rate einer Landingpage: Anfragen je Aufruf. */
    public static function conversion(array $seite): ?float
    {
        $aufrufe = (int) $seite['aufrufe'];
        if ($aufrufe < 20) {
            return null;   // unter 20 Aufrufen ist jede Quote Zufall
        }
        return (int) $seite['conversions'] / $aufrufe * 100;
    }

    /**
     * Die Seiten, die ein neuer Workspace bekommt. Eine leere Website wäre
     * der schlechteste erste Eindruck – hier steht eine vollständige, die
     * der Pro nur noch anpassen muss.
     */
    public static function vorlage(string $name, string $schwerpunkt = 'allgemein'): array
    {
        $ort = Tenant::one('locations', 'aktiv = 1');
        $stadt = $ort ? (string) $ort['ort'] : '';

        /*
         * Der Aufmacher.
         *
         * Die Sternchen im Titel setzen den zweiten Satz auf den gelben
         * Textmarker. Die Notiz darunter ist der Satz, den man am Telefon
         * sagen würde – sie steht in Handschrift und nimmt genau die
         * Sorge vorweg, die Anfänger vom Anrufen abhält.
         */
        $hero = Bloecke::neu('hero');
        $hero['daten']['obertitel'] = $name;
        $hero['daten']['titel'] = match ($schwerpunkt) {
            'anfaenger' => 'Ihr Weg zur Platzreife. *Ohne Umwege.*',
            'leistung'  => 'Vom soliden Spieler *zum Turnierspieler.*',
            'junior'    => 'Golf, das Kindern *Spaß macht.*',
            default     => 'Eine Golfschule, die Sie *beim Namen kennt.*',
        };
        $hero['daten']['text'] = match ($schwerpunkt) {
            'anfaenger' => 'In wenigen Wochen von den ersten Schlägen bis auf den Platz – in kleinen Gruppen, mit klarem Ablauf und ohne Druck.',
            'leistung'  => 'Videoanalyse, Leistungsdaten und ein Trainingsplan, der auf Ihr Handicap-Ziel ausgerichtet ist. Eine Baustelle pro Quartal, nicht fünf.',
            'junior'    => 'Spielerisches Training für Kinder und Jugendliche, in Gruppen mit gleichaltrigen Spielerinnen und Spielern.',
            default     => 'Platzreife, Einzelstunden und ein Saisonprogramm für Spieler, die ihr Handicap ernst nehmen. Kleine Gruppen. Keine Kurspakete, die niemand versteht.',
        };
        $hero['daten']['notiz'] = match ($schwerpunkt) {
            'junior'    => 'Schläger in der richtigen Länge sind da — mitbringen muss man nichts.',
            'leistung'  => 'Wer schon spielt, fängt am besten mit einer Standortbestimmung an.',
            default     => 'Erste Stunde? Schläger und Bälle stelle ich — Sportschuhe genügen.',
        };
        $hero['daten']['fakten'] = 'PGA Professional · Videoanalyse · Kleine Gruppen'
            . ($stadt !== '' ? ' · ' . $stadt : '');
        $hero['daten']['knopf_text'] = 'Freie Termine ansehen';
        $hero['daten']['knopf2_text'] = 'Preise, alle';

        /* Was gerade ansteht – drei kurze Karten mit einem Datum. */
        $aktuell = Bloecke::neu('spalten');
        $aktuell['daten']['titel'] = 'Was hier gerade los ist';
        $aktuell['daten']['text'] = 'Kurzfristige Plätze, Termine und was sonst auf dem Zettel steht.';
        $aktuell['daten']['eintraege'] = [
            ['titel' => 'Platzreifekurs im Frühjahr',
             'text' => 'Sechs Termine, kleine Gruppe, Prüfung inklusive. Der Kurs startet, sobald sechs Plätze belegt sind.'],
            ['titel' => 'Kurzfristige Plätze',
             'text' => 'Diese Woche sind noch Einzelstunden frei. Wer flexibel ist, findet fast immer einen Termin.'],
            ['titel' => 'Wintertraining indoor',
             'text' => 'Von November bis März mit Abschlagnetz und Videoanalyse – an der Technik lässt sich auch ohne Platz arbeiten.'],
        ];

        $zahlen = Bloecke::neu('zahlen');
        $zahlen['daten']['eintraege'] = [
            ['wert' => '12', 'label' => 'Jahre Unterricht'],
            ['wert' => '340', 'label' => 'begleitete Platzreifen'],
            ['wert' => '4,9', 'label' => 'Bewertung'],
        ];

        $trainer = Bloecke::neu('team');
        $trainer['daten']['titel'] = '';

        $leistungen = Bloecke::neu('leistungen');
        $leistungen['daten']['obertitel'] = 'Kursangebot & Preise';
        $leistungen['daten']['titel'] = 'Alle Wege, besser zu werden.';
        $leistungen['daten']['text'] = 'Sie brauchen genau einen davon. Welchen, klären wir in zehn Minuten am Telefon.';

        $stimmen = Bloecke::neu('testimonials');
        $stimmen['daten']['titel'] = 'Was Spieler sagen';
        $stimmen['daten']['eintraege'] = [
            ['text' => 'In zwei Saisons von 28 auf 16 — vor allem, weil wir irgendwann aufgehört haben, am Schwung zu basteln.',
             'name' => 'Andreas B.', 'zusatz' => 'Hcp 16,4', 'sterne' => 5],
            ['text' => 'Er erklärt nie mehr, als man in dem Moment gebrauchen kann. Das ist seltener, als man denkt.',
             'name' => 'Dr. Miriam K.', 'zusatz' => 'Platzreife 2025', 'sterne' => 5],
            ['text' => 'Vier Playing Lessons haben mehr gebracht als drei Jahre allein auf der Range.',
             'name' => 'Stefan L.', 'zusatz' => 'Hcp 9,1', 'sterne' => 5],
        ];

        $faq = Bloecke::neu('faq');
        $faq['daten']['titel'] = 'Was am häufigsten gefragt wird';
        $faq['daten']['eintraege'] = [
            ['frage' => 'Brauche ich eigene Schläger?',
             'antwort' => 'Für die ersten Stunden nicht. Leihschläger stehen bereit – und wir schauen gemeinsam, was zu Ihnen passt, bevor Sie etwas kaufen.'],
            ['frage' => 'Wie lange dauert es bis zur Platzreife?',
             'antwort' => 'Die meisten brauchen sechs bis zehn Wochen bei einer Einheit pro Woche. Es kommt darauf an, wie oft Sie zusätzlich üben.'],
            ['frage' => 'Kann ich einen Termin verschieben?',
             'antwort' => 'Ja, bis 24 Stunden vorher kostenfrei – am einfachsten über Ihren Zugang oder telefonisch.'],
            ['frage' => 'Findet das Training auch bei Regen statt?',
             'antwort' => 'Bei leichtem Regen ja. Bei Gewitter, Sturm oder gesperrtem Platz holen wir den Termin ohne Kosten nach.'],
        ];

        $buchung = Bloecke::neu('buchung');
        $buchung['daten']['obertitel'] = 'Buchung';
        $buchung['daten']['titel'] = 'Drei Angaben, dann haben Sie den Termin.';
        $buchung['daten']['text'] = 'Keine Registrierung, keine Kreditkarte. Bezahlt wird vor Ort oder per Rechnung.';

        $cta = Bloecke::neu('cta');
        $cta['daten']['notiz'] = 'Anrufen geht meistens schneller.';

        $kontakt = Bloecke::neu('kontakt');
        $kontakt['daten']['obertitel'] = 'Kontakt';
        $kontakt['daten']['titel'] = 'So finden Sie her.';
        $kontakt['daten']['karte'] = true;

        return [$hero, $aktuell, $trainer, $zahlen, $leistungen, $stimmen, $buchung, $faq, $cta, $kontakt];
    }
}
