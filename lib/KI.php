<?php
/**
 * KI – der Assistent.
 *
 * Drei Grundsätze, die hier als Code stehen:
 *
 * **Zahlen kommen aus der Datenbank, nicht aus dem Modell.** Fragen wie
 * „Was war mein Umsatz im August?“ beantwortet `datenAntwort()` mit einer
 * echten Abfrage. Ein Sprachmodell, das Umsatzzahlen schätzt, ist im besten
 * Fall nutzlos. Das Modell formuliert und berät – es rechnet nicht.
 *
 * **Nichts geschieht ohne Bestätigung.** Die KI erzeugt Entwürfe. Versenden,
 * Preise ändern, Rechnungen schreiben, löschen – das macht ein Mensch mit
 * einem Klick, immer.
 *
 * **Ohne Schlüssel funktioniert alles weiter.** Ist kein KI-Zugang
 * hinterlegt, arbeiten die Generatoren regelbasiert aus den eigenen Daten
 * und einer gepflegten Textbasis. Das Ergebnis ist schlichter, aber
 * brauchbar – und ehrlich gekennzeichnet.
 */
final class KI
{
    public static function verfuegbar(): bool
    {
        return trim((string) Config::get('ai.api_key', '')) !== '';
    }

    public static function modus(): string
    {
        return self::verfuegbar() ? 'modell' : 'regeln';
    }

    /* ================================================= Fragen & Antwort */

    /**
     * @return array{text:string,daten:array,quelle:string,link:string}
     */
    public static function frage(string $frage): array
    {
        $direkt = self::datenAntwort($frage);
        if ($direkt !== null) {
            return $direkt;
        }
        if (self::verfuegbar()) {
            $antwort = self::modell(self::systemText(), [
                ['rolle' => 'user', 'text' => "Kontext des Workspace:\n" . self::kontext() . "\n\nFrage: " . $frage],
            ]);
            if ($antwort !== '') {
                return ['text' => $antwort, 'daten' => [], 'quelle' => 'modell', 'link' => ''];
            }
        }
        return [
            'text' => "Dazu finde ich keine passende Auswertung.\n\n"
                    . "Ich kann dir unter anderem beantworten:\n"
                    . "• Umsatz eines Zeitraums („Was war mein Umsatz im August?“)\n"
                    . "• Kunden ohne Termin („Wen habe ich seit 90 Tagen nicht gesehen?“)\n"
                    . "• Bestseller („Welche Leistungen verkaufen sich am besten?“)\n"
                    . "• Auslastung („Wie voll ist meine nächste Woche?“)\n"
                    . "• Offene Posten („Welche Rechnungen sind überfällig?“)\n"
                    . "• Kontaktvorschläge („Wen sollte ich heute anrufen?“)",
            'daten' => [], 'quelle' => 'regeln', 'link' => '',
        ];
    }

    /**
     * Beantwortet die häufigen Fragen unmittelbar aus den Daten.
     *
     * @return array{text:string,daten:array,quelle:string,link:string}|null
     */
    private static function datenAntwort(string $frage): ?array
    {
        $f = mb_strtolower($frage);

        /* Umsatz */
        if (preg_match('/umsatz|eingenommen|verdient|einnahmen/u', $f)) {
            [$von, $bis, $name] = self::zeitraumAusText($f);
            $umsatz = Commerce::umsatz($von, $bis);
            $anzahl = Tenant::count('orders', "status = 'bezahlt' AND bezahlt_am >= :v AND bezahlt_am <= :b",
                ['v' => $von . ' 00:00:00', 'b' => $bis . ' 23:59:59']);
            $bestseller = Commerce::bestseller(3, max(1, (int) ceil((strtotime($bis) - strtotime($von)) / 86400)));

            $text = $name . ' hast du ' . Util::geld($umsatz) . ' eingenommen'
                  . ($anzahl > 0 ? ', verteilt auf ' . $anzahl . ' bezahlte Bestellung' . ($anzahl === 1 ? '' : 'en') : '')
                  . '.';
            if ($bestseller !== []) {
                $text .= "\n\nAm meisten gebracht hat:";
                foreach ($bestseller as $b) {
                    $text .= "\n• " . $b['titel'] . ' – ' . Util::geld($b['umsatz']) . ' (' . $b['anzahl'] . '×)';
                }
            }
            return ['text' => $text, 'daten' => ['umsatz' => $umsatz], 'quelle' => 'daten', 'link' => '/app/auswertung.php'];
        }

        /* Inaktive Kunden */
        if (preg_match('/(nicht|nie).{0,20}(gesehen|gebucht|da gewesen)|inaktiv|lange nicht/u', $f)) {
            $tage = 90;
            if (preg_match('/(\d{2,3})\s*tag/u', $f, $t)) {
                $tage = (int) $t[1];
            }
            [$wo, $p] = Segments::bedingung(['bedingungen' => [
                ['feld' => 'tage_seit_termin', 'op' => '>=', 'wert' => (string) $tage],
                ['feld' => 'status', 'op' => '=', 'wert' => 'aktiv'],
            ]]);
            $kunden = Tenant::all('customers', $wo, $p, 'nachname', 15);
            $gesamt = Tenant::count('customers', $wo, $p);

            if ($gesamt === 0) {
                return ['text' => 'Sehr gut – niemand ist seit ' . $tage . ' Tagen ohne Termin.',
                        'daten' => [], 'quelle' => 'daten', 'link' => '/app/kunden.php'];
            }
            $text = $gesamt . ' Kunde' . ($gesamt === 1 ? '' : 'n') . ' ' . ($gesamt === 1 ? 'war' : 'waren')
                  . ' seit mindestens ' . $tage . ' Tagen nicht mehr da:';
            foreach ($kunden as $k) {
                $text .= "\n• " . Customers::name($k)
                       . ((string) $k['hcp'] !== '' ? ' (HCP ' . Util::hcp((string) $k['hcp']) . ')' : '');
            }
            if ($gesamt > count($kunden)) {
                $text .= "\n… und " . ($gesamt - count($kunden)) . ' weitere.';
            }
            return ['text' => $text, 'daten' => ['anzahl' => $gesamt], 'quelle' => 'daten',
                    'link' => '/app/kunden.php?filter=inaktiv'];
        }

        /* Bestseller */
        if (preg_match('/verkauf|bestseller|beliebtest|läuft am besten|laeuft am besten|meistverkauft/u', $f)) {
            $liste = Commerce::bestseller(8, 365);
            if ($liste === []) {
                return ['text' => 'Es gibt noch keine bezahlten Bestellungen, aus denen sich das ablesen ließe.',
                        'daten' => [], 'quelle' => 'daten', 'link' => '/app/produkte.php'];
            }
            $text = "Das verkauft sich im letzten Jahr am besten:";
            foreach ($liste as $i => $b) {
                $text .= "\n" . ($i + 1) . '. ' . $b['titel'] . ' – ' . $b['anzahl'] . '× für '
                       . Util::geld($b['umsatz']);
            }
            return ['text' => $text, 'daten' => $liste, 'quelle' => 'daten', 'link' => '/app/auswertung.php'];
        }

        /* Auslastung */
        if (preg_match('/auslastung|wie voll|ausgelastet|frei.{0,12}termine|kapazität|kapazitaet/u', $f)) {
            $von = date('Y-m-d', strtotime('monday this week'));
            $bis = date('Y-m-d', strtotime('sunday this week'));
            if (str_contains($f, 'nächste') || str_contains($f, 'naechste') || str_contains($f, 'kommende')) {
                $von = date('Y-m-d', strtotime('monday next week'));
                $bis = date('Y-m-d', strtotime('sunday next week'));
            }
            $quote = Bookings::auslastung($von, $bis);
            if ($quote === null) {
                return ['text' => 'Ohne hinterlegte Arbeitszeiten lässt sich keine Auslastung berechnen. '
                                . 'Trag sie unter Buchungen → Verfügbarkeit ein, dann rechne ich das aus.',
                        'daten' => [], 'quelle' => 'daten', 'link' => '/app/verfuegbarkeit.php'];
            }
            $termine = count(Bookings::zeitraum($von . ' 00:00:00', $bis . ' 23:59:59'));
            return [
                'text' => 'Vom ' . Util::datum($von) . ' bis ' . Util::datum($bis) . ' bist du zu '
                        . round($quote) . ' % ausgelastet – ' . $termine . ' Termin' . ($termine === 1 ? '' : 'e') . '.'
                        . ($quote < 45 ? "\n\nDa ist noch Luft. Ein kurzer Anstoß an inaktive Kunden füllt das erfahrungsgemäß schnell." : ''),
                'daten' => ['quote' => $quote], 'quelle' => 'daten', 'link' => '/app/kalender.php',
            ];
        }

        /* Offene Rechnungen */
        if (preg_match('/rechnung|offene posten|überfällig|ueberfaellig|schuldet|bezahlt nicht/u', $f)) {
            $offen = Tenant::all('invoices', "status IN ('offen','ueberfaellig')", [], 'faellig', 12);
            $summe = Invoices::offenerBetrag();
            if ($offen === []) {
                return ['text' => 'Es ist keine Rechnung offen. Alles bezahlt.',
                        'daten' => [], 'quelle' => 'daten', 'link' => '/app/rechnungen.php'];
            }
            $text = count($offen) . ' offene Rechnung' . (count($offen) === 1 ? '' : 'en')
                  . ' über zusammen ' . Util::geld($summe) . ':';
            foreach ($offen as $r) {
                $text .= "\n• " . $r['nummer'] . ' · ' . Customers::nameVonId((int) $r['customer_id'])
                       . ' · ' . Util::geld((int) $r['summe_cent'] - (int) $r['bezahlt_cent'])
                       . ' · fällig ' . Util::datum((string) $r['faellig'])
                       . ($r['status'] === 'ueberfaellig' ? ' (überfällig)' : '');
            }
            return ['text' => $text, 'daten' => ['summe' => $summe], 'quelle' => 'daten',
                    'link' => '/app/rechnungen.php'];
        }

        /* Kontaktvorschläge */
        if (preg_match('/wen.{0,25}(anrufen|kontaktieren|melden)|kontaktieren sollte|heute tun/u', $f)) {
            $liste = Empfehlungen::naechsteKontakte(6);
            if ($liste === []) {
                return ['text' => 'Im Moment steht nichts an – alle aktiven Kunden haben einen Termin oder waren kürzlich da.',
                        'daten' => [], 'quelle' => 'daten', 'link' => '/app/kunden.php'];
            }
            $text = "Diese Kunden würde ich heute ansprechen:";
            foreach ($liste as $v) {
                $text .= "\n• " . Customers::name($v['kunde']) . ' – ' . $v['grund'];
            }
            return ['text' => $text, 'daten' => [], 'quelle' => 'daten', 'link' => '/app/kunden.php'];
        }

        /* Termine heute / morgen */
        if (preg_match('/termine?.{0,15}(heute|morgen)|was steht (heute|morgen)|kalender heute/u', $f)) {
            $morgen = str_contains($f, 'morgen');
            $tag = $morgen ? date('Y-m-d', strtotime('+1 day')) : Util::heute();
            $termine = Bookings::zeitraum($tag . ' 00:00:00', $tag . ' 23:59:59');
            if ($termine === []) {
                return ['text' => ($morgen ? 'Morgen' : 'Heute') . ' steht kein Termin an.',
                        'daten' => [], 'quelle' => 'daten', 'link' => '/app/kalender.php'];
            }
            $text = ($morgen ? 'Morgen' : 'Heute') . ' hast du ' . count($termine) . ' Termin'
                  . (count($termine) === 1 ? '' : 'e') . ':';
            foreach ($termine as $b) {
                $text .= "\n• " . Util::uhrzeit((string) $b['start']) . ' – ' . $b['titel']
                       . ((int) $b['customer_id'] > 0 ? ' mit ' . Customers::nameVonId((int) $b['customer_id']) : '');
            }
            return ['text' => $text, 'daten' => [], 'quelle' => 'daten', 'link' => '/app/kalender.php'];
        }

        /* Neue Kunden */
        if (preg_match('/neue kunden|neukunden|dazugekommen/u', $f)) {
            [$von, $bis, $name] = self::zeitraumAusText($f);
            $anzahl = Tenant::count('customers', 'erstellt >= :v AND erstellt <= :b',
                ['v' => $von . ' 00:00:00', 'b' => $bis . ' 23:59:59']);
            $quellen = Leads::quellen(90);
            $text = $name . ' sind ' . $anzahl . ' neue Kunden dazugekommen.';
            if ($quellen !== []) {
                $text .= "\n\nWoher die Anfragen der letzten 90 Tage kamen:";
                foreach (array_slice($quellen, 0, 5) as $q) {
                    $text .= "\n• " . $q['quelle'] . ': ' . $q['anzahl'] . ' Anfragen, davon '
                           . $q['kunden'] . ' zu Kunden geworden';
                }
            }
            return ['text' => $text, 'daten' => ['anzahl' => $anzahl], 'quelle' => 'daten',
                    'link' => '/app/kunden.php'];
        }

        return null;
    }

    /** @return array{0:string,1:string,2:string} [von, bis, Bezeichnung] */
    private static function zeitraumAusText(string $f): array
    {
        $monate = ['januar' => 1, 'februar' => 2, 'märz' => 3, 'maerz' => 3, 'april' => 4, 'mai' => 5,
                   'juni' => 6, 'juli' => 7, 'august' => 8, 'september' => 9, 'oktober' => 10,
                   'november' => 11, 'dezember' => 12];
        foreach ($monate as $name => $nr) {
            if (str_contains($f, $name)) {
                $jahr = preg_match('/\b(20\d{2})\b/', $f, $t) ? (int) $t[1] : (int) date('Y');
                if ($jahr === (int) date('Y') && $nr > (int) date('n')) {
                    $jahr--;
                }
                $von = sprintf('%04d-%02d-01', $jahr, $nr);
                return [$von, date('Y-m-t', strtotime($von)), 'Im ' . ucfirst($name) . ' ' . $jahr];
            }
        }
        if (str_contains($f, 'heute')) {
            return [Util::heute(), Util::heute(), 'Heute'];
        }
        if (str_contains($f, 'gestern')) {
            $t = date('Y-m-d', strtotime('-1 day'));
            return [$t, $t, 'Gestern'];
        }
        if (str_contains($f, 'woche')) {
            return [date('Y-m-d', strtotime('monday this week')), Util::heute(), 'Diese Woche'];
        }
        if (str_contains($f, 'jahr')) {
            return [date('Y-01-01'), Util::heute(), 'Dieses Jahr'];
        }
        if (str_contains($f, 'letzten monat') || str_contains($f, 'vormonat')) {
            return [date('Y-m-01', strtotime('-1 month')), date('Y-m-t', strtotime('-1 month')), 'Im letzten Monat'];
        }
        return [date('Y-m-01'), Util::heute(), 'Diesen Monat'];
    }

    /* ================================================== Textgeneratoren */

    /**
     * Website-Entwurf aus einer Beschreibung. Ohne Modell setzt sich der
     * Entwurf aus einer Textbasis zusammen, die auf den erkannten
     * Schwerpunkt abgestimmt ist – kein Blindtext.
     *
     * @return array{bloecke:array,seo:array,hinweis:string}
     */
    public static function websiteEntwurf(string $beschreibung, string $name = ''): array
    {
        $name = $name !== '' ? $name : Tenant::name();
        $schwerpunkt = self::schwerpunkt($beschreibung);

        if (self::verfuegbar()) {
            $json = self::modellJson(
                'Du bist Texter für Websites von Golf Professionals. Antworte ausschließlich mit JSON.',
                "Erstelle Website-Texte auf Deutsch für: \"{$beschreibung}\"\n"
                . "Name des Pros: {$name}\n\n"
                . 'Format: {"hero":{"obertitel":"","titel":"","text":"","knopf_text":""},'
                . '"vorteile":[{"titel":"","text":""}],'
                . '"leistungen":[{"titel":"","text":"","preis":"","dauer":""}],'
                . '"faq":[{"frage":"","antwort":""}],'
                . '"stimmen":[{"text":"","name":"","zusatz":""}],'
                . '"cta":{"titel":"","text":"","knopf_text":""},'
                . '"seo":{"titel":"","beschreibung":""},'
                . '"blogideen":["",""]}'
                . "\nSchreib nüchtern und konkret, keine Superlative, keine Ausrufezeichen. "
                . "Du-Ansprache. Deutsche Rechtschreibung."
            );
            if ($json !== null) {
                return self::entwurfAusJson($json, $name);
            }
        }
        return self::entwurfAusRegeln($schwerpunkt, $name, $beschreibung);
    }

    private static function schwerpunkt(string $text): string
    {
        $t = mb_strtolower($text);
        return match (true) {
            str_contains($t, 'platzreife') || str_contains($t, 'anfänger') || str_contains($t, 'anfaenger')
                || str_contains($t, 'einsteiger') => 'anfaenger',
            str_contains($t, 'kind') || str_contains($t, 'jugend') || str_contains($t, 'junior') => 'junior',
            str_contains($t, 'turnier') || str_contains($t, 'leistung') || str_contains($t, 'einstellig')
                || str_contains($t, 'wettkampf') => 'leistung',
            str_contains($t, 'akademie') || str_contains($t, 'academy') || str_contains($t, 'team') => 'academy',
            str_contains($t, 'fitness') || str_contains($t, 'athletik') => 'fitness',
            str_contains($t, 'mental') => 'mental',
            default => 'allgemein',
        };
    }

    /** Die Textbasis für den regelbasierten Entwurf. */
    private static function textbasis(string $schwerpunkt): array
    {
        $basis = [
            'anfaenger' => [
                'obertitel' => 'Golftraining für Einsteiger',
                'titel' => 'In acht Wochen zur Platzreife. Ohne Umwege.',
                'text' => 'Vom ersten Schlag bis auf den Platz – in kleinen Gruppen, mit festem Ablauf und ohne den Druck, schon etwas können zu müssen. Schläger stehen bereit.',
                'notiz' => 'Erste Stunde? Schläger und Bälle stelle ich — Sportschuhe genügen.',
                'fakten' => 'PGA Professional · Kleine Gruppen · Prüfung inklusive',
                'vorteile' => [
                    ['Kleine Gruppen', 'Höchstens sechs Teilnehmer. So bleibt genug Zeit für jeden Einzelnen.'],
                    ['Alles inklusive', 'Leihschläger, Bälle und Unterlagen sind dabei. Du brauchst nur bequeme Schuhe.'],
                    ['Fester Ablauf', 'Du weißt von Anfang an, was in welcher Woche drankommt und wann die Prüfung ist.'],
                ],
                'faq' => [
                    ['Brauche ich eigene Schläger?', 'Nein. Für den Kurs stelle ich alles. Wenn du später eigene Schläger möchtest, schauen wir gemeinsam, was passt.'],
                    ['Muss ich sportlich sein?', 'Nein. Golf lässt sich in jedem Alter und mit jeder Grundfitness lernen. Wir gehen das Tempo an, das zu dir passt.'],
                    ['Wie lange dauert die Platzreife?', 'Bei einer Einheit pro Woche meist sechs bis zehn Wochen. Wer zwischendurch übt, ist schneller.'],
                    ['Was kostet der Einstieg?', 'Der Platzreifekurs kostet 349 € inklusive Leihmaterial und Prüfung. Eine Einzelstunde liegt bei 75 €.'],
                ],
            ],
            'leistung' => [
                'obertitel' => 'Leistungstraining',
                'titel' => 'Ein Handicap tiefer. Mit Zahlen statt Gefühl.',
                'text' => 'Videoanalyse, Launch-Monitor-Daten und ein Trainingsplan, der auf dein konkretes Ziel ausgerichtet ist – nicht auf allgemeine Ratschläge.',
                'notiz' => 'Wer schon spielt, fängt am besten mit einer Standortbestimmung an.',
                'fakten' => 'Trackman · Videoanalyse · Schriftlicher Trainingsplan',
                'vorteile' => [
                    ['Messbar', 'Schlägerkopfgeschwindigkeit, Carry, Streuung. Wir arbeiten mit Zahlen, nicht mit Eindrücken.'],
                    ['Videoanalyse', 'Dein Schwung in Zeitlupe, mit Linien und Anmerkungen – jederzeit nachschaubar.'],
                    ['Turniervorbereitung', 'Platzstrategie, Routine und Umgang mit Druck gehören ins Training, nicht erst ins Turnier.'],
                ],
                'faq' => [
                    ['Für welches Handicap ist das geeignet?', 'Ab etwa Handicap 26 abwärts. Darunter arbeiten wir stärker an Feinheiten und Scoring.'],
                    ['Wie oft sollte ich trainieren?', 'Einmal pro Woche Unterricht plus zwei eigene Einheiten bringt in einer Saison typischerweise drei bis fünf Schläge.'],
                    ['Bekomme ich die Videos?', 'Ja. Alle Analysen liegen in deinem Bereich und bleiben dort dauerhaft verfügbar.'],
                ],
            ],
            'junior' => [
                'obertitel' => 'Golf für Kinder und Jugendliche',
                'titel' => 'Golf für Kinder, das wirklich Spaß macht.',
                'text' => 'Spielerisches Training in altersgerechten Gruppen. Bewegung, Technik und Regeln kommen dabei nebenbei – weil Kinder am besten lernen, wenn sie nicht merken, dass sie lernen.',
                'notiz' => 'Schläger in der richtigen Länge sind da — mitbringen muss man nichts.',
                'fakten' => 'Gruppen nach Alter · Ab sechs Jahren · Ferienprogramm',
                'vorteile' => [
                    ['Altersgerechte Gruppen', 'Getrennt nach Alter und Können, damit niemand über- oder unterfordert ist.'],
                    ['Material inklusive', 'Kindergerechte Schläger stehen bereit und wachsen mit.'],
                    ['Feste Termine', 'Immer zur selben Zeit – gut planbar neben Schule und anderen Hobbys.'],
                ],
                'faq' => [
                    ['Ab welchem Alter?', 'Ab sechs Jahren. Jüngere Kinder können gern zu einer Schnupperstunde mitkommen.'],
                    ['Müssen Eltern dabei sein?', 'Beim ersten Mal gern, danach nicht nötig. Viele Kinder sind ohne Publikum konzentrierter.'],
                    ['Was kostet es?', 'Das Jahrestraining kostet 390 € für 30 Einheiten, Geschwisterkinder bekommen 20 Prozent Nachlass.'],
                ],
            ],
            'academy' => [
                'obertitel' => 'Golf Academy',
                'titel' => 'Ein Team, ein Konzept, alle Spielstärken',
                'text' => 'Vom ersten Schlag bis zum Turnierspiel: mehrere Trainer, abgestimmte Trainingswege und ein gemeinsames Verständnis davon, wie Golf gelernt wird.',
                'notiz' => 'Wer zu wem passt, klären wir beim ersten Termin — nicht vorher am Telefon.',
                'fakten' => 'Mehrere Trainer · Alle Spielstärken · Sechs Tage die Woche',
                'vorteile' => [
                    ['Abgestimmtes Team', 'Jeder Trainer kennt deinen Stand – auch bei einem Wechsel geht nichts verloren.'],
                    ['Alle Spielstärken', 'Platzreife, Handicap-Verbesserung, Turniervorbereitung und Juniorenförderung unter einem Dach.'],
                    ['Feste Trainingswege', 'Klare Stufen mit nachvollziehbaren Zielen statt beliebiger Einzelstunden.'],
                ],
                'faq' => [
                    ['Kann ich den Trainer wechseln?', 'Ja, jederzeit. Dein Trainingsstand ist im System hinterlegt, der neue Trainer sieht ihn sofort.'],
                    ['Gibt es Gruppenangebote?', 'Ja, für jede Spielstärke. Gruppen sind günstiger und für viele der bessere Einstieg.'],
                ],
            ],
            'allgemein' => [
                'obertitel' => 'PGA Golf Professional',
                'titel' => 'Besser Golf spielen. Mit einem Plan.',
                'text' => 'Individuelles Training für Einsteiger und Fortgeschrittene – mit klaren Zielen, Videoanalyse und einem Trainingsplan, der zu deinem Alltag passt.',
                'notiz' => 'Erste Stunde? Schläger und Bälle stelle ich — Sportschuhe genügen.',
                'fakten' => 'PGA Professional · Videoanalyse · Kleine Gruppen',
                'vorteile' => [
                    ['Klares Ziel', 'Wir legen gemeinsam fest, woran du arbeitest. Messbar statt gefühlt.'],
                    ['Videoanalyse', 'Dein Schwung in Zeitlupe, mit Linien und Anmerkungen zum Nachschauen.'],
                    ['Trainingsplan', 'Übungen für zu Hause und die Range, abgestimmt auf deine Zeit.'],
                ],
                'faq' => [
                    ['Für wen ist das Training geeignet?', 'Für alle Spielstärken – vom ersten Schläger bis zum einstelligen Handicap.'],
                    ['Wie buche ich einen Termin?', 'Direkt auf dieser Seite. Du siehst die freien Zeiten und bekommst sofort eine Bestätigung.'],
                    ['Kann ich einen Termin verschieben?', 'Ja, bis 24 Stunden vorher kostenfrei.'],
                    ['Gibt es Trainingspakete?', 'Ja. Mehrere Einheiten im Paket sind günstiger als Einzelstunden und ein Jahr lang gültig.'],
                ],
            ],
        ];
        $basis['fitness'] = $basis['leistung'];
        $basis['mental']  = $basis['leistung'];
        return $basis[$schwerpunkt] ?? $basis['allgemein'];
    }

    /**
     * Setzt den letzten Satz einer Überschrift auf den gelben Textmarker.
     *
     * Getrennt wird am letzten Punkt vor dem Schluss; hat die Überschrift
     * nur einen Satz, wird sein zweiter Teil ab dem letzten Komma betont.
     * Findet sich nichts davon, bleibt der Titel wie er ist – lieber keine
     * Betonung als eine an der falschen Stelle.
     */
    private static function titelMitMarker(string $titel): string
    {
        $titel = trim($titel);
        if ($titel === '' || str_contains($titel, '*')) {
            return $titel;
        }
        foreach (['. ', ', '] as $trenner) {
            $pos = mb_strrpos(rtrim($titel, '.'), $trenner);
            if ($pos !== false && $pos > 8 && mb_strlen($titel) - $pos > 6) {
                $vorn = mb_substr($titel, 0, $pos + mb_strlen($trenner));
                $rest = mb_substr($titel, $pos + mb_strlen($trenner));
                return $vorn . '*' . $rest . '*';
            }
        }
        return $titel;
    }

    private static function entwurfAusRegeln(string $schwerpunkt, string $name, string $beschreibung): array
    {
        $t = self::textbasis($schwerpunkt);

        $hero = Bloecke::neu('hero');
        $hero['daten']['obertitel']  = $t['obertitel'];
        /*
         * Der letzte Satz der Überschrift kommt auf den gelben Textmarker.
         * Ein Titel ohne Sternchen bliebe einfarbig – das sähe nicht falsch
         * aus, aber der Entwurf lebt von genau dieser einen Betonung.
         */
        $hero['daten']['titel']      = self::titelMitMarker($t['titel']);
        $hero['daten']['text']       = $t['text'];
        $hero['daten']['notiz']      = $t['notiz'] ?? '';
        $hero['daten']['fakten']     = $t['fakten'] ?? '';
        $hero['daten']['knopf_text'] = 'Freie Termine ansehen';
        $hero['daten']['knopf_url']  = '#buchung';
        $hero['daten']['knopf2_text'] = 'Preise, alle';
        $hero['daten']['knopf2_url'] = '#leistungen';

        $spalten = Bloecke::neu('spalten');
        $spalten['daten']['titel'] = 'Was hier gerade los ist';
        $spalten['daten']['text']  = 'Kurzfristige Plätze, Termine und was sonst auf dem Zettel steht.';
        $spalten['daten']['eintraege'] = [];
        foreach ($t['vorteile'] as [$titel, $text]) {
            $spalten['daten']['eintraege'][] = ['titel' => $titel, 'text' => $text];
        }

        $trainer = Bloecke::neu('team');
        $trainer['daten']['titel'] = '';

        $leistungen = Bloecke::neu('leistungen');
        $leistungen['daten']['obertitel'] = 'Kursangebot & Preise';
        $leistungen['daten']['titel'] = 'Alle Wege, besser zu werden.';
        $leistungen['daten']['text']  = 'Sie brauchen genau einen davon. Welchen, klären wir in zehn Minuten am Telefon.';

        $stimmen = Bloecke::neu('testimonials');
        $stimmen['daten']['eintraege'] = [
            ['text' => 'Nach acht Wochen hatte ich meine Platzreife – und zum ersten Mal das Gefühl, zu wissen, was ich da tue.',
             'name' => 'Sandra K.', 'zusatz' => 'Platzreifekurs', 'sterne' => 5],
            ['text' => 'Die Videoanalyse war der Wendepunkt. Ich sehe jetzt selbst, woran ich arbeiten muss.',
             'name' => 'Michael B.', 'zusatz' => 'HCP 18,4', 'sterne' => 5],
            ['text' => 'Klare Ansagen, kein Fachchinesisch, und nach jeder Stunde weiß ich, was ich üben soll.',
             'name' => 'Tobias R.', 'zusatz' => 'HCP 26,1', 'sterne' => 5],
        ];

        $faq = Bloecke::neu('faq');
        $faq['daten']['eintraege'] = array_map(
            static fn($p) => ['frage' => $p[0], 'antwort' => $p[1]],
            $t['faq']
        );

        $cta = Bloecke::neu('cta');
        $cta['daten']['titel'] = 'Sagen Sie kurz, was Sie vorhaben.';
        $cta['daten']['text']  = 'Drei Sätze genügen. Sie bekommen einen konkreten Vorschlag zurück, '
                               . 'in der Regel noch am selben Abend.';
        $cta['daten']['notiz'] = 'Anrufen geht meistens schneller.';

        $buchung = Bloecke::neu('buchung');
        $buchung['daten']['obertitel'] = 'Buchung';
        $buchung['daten']['titel'] = 'Drei Angaben, dann haben Sie den Termin.';
        $buchung['daten']['text'] = 'Keine Registrierung, keine Kreditkarte. '
                                  . 'Bezahlt wird vor Ort oder per Rechnung.';

        $kontakt = Bloecke::neu('kontakt');
        $kontakt['daten']['obertitel'] = 'Kontakt';
        $kontakt['daten']['titel'] = 'So finden Sie her.';
        $kontakt['daten']['karte'] = true;

        $zahlen = Bloecke::neu('zahlen');

        return [
            'bloecke' => [$hero, $spalten, $trainer, $zahlen, $leistungen, $stimmen, $buchung, $faq, $cta, $kontakt],
            'seo' => [
                'titel' => Util::kuerzen($name . ' – ' . $t['titel'], 60),
                'beschreibung' => Util::kuerzen($t['text'], 155),
            ],
            'hinweis' => self::verfuegbar()
                ? 'Entwurf erstellt.'
                : 'Entwurf aus der eingebauten Textbasis erstellt – ohne hinterlegten KI-Zugang. '
                  . 'Alles ist bearbeitbar.',
        ];
    }

    private static function entwurfAusJson(array $j, string $name): array
    {
        $hero = Bloecke::neu('hero');
        foreach (['obertitel', 'titel', 'text', 'knopf_text'] as $feld) {
            if (!empty($j['hero'][$feld])) {
                $hero['daten'][$feld] = (string) $j['hero'][$feld];
            }
        }
        $hero['daten']['titel'] = self::titelMitMarker((string) $hero['daten']['titel']);
        $hero['daten']['knopf_url'] = '#buchung';

        $spalten = Bloecke::neu('spalten');
        $spalten['daten']['eintraege'] = [];
        $icons = ['target', 'video', 'training', 'award'];
        foreach ((array) ($j['vorteile'] ?? []) as $i => $v) {
            $spalten['daten']['eintraege'][] = [
                'icon' => $icons[$i % count($icons)],
                'titel' => (string) ($v['titel'] ?? ''), 'text' => (string) ($v['text'] ?? ''),
            ];
        }

        $leistungen = Bloecke::neu('leistungen');
        if (!empty($j['leistungen'])) {
            $leistungen['daten']['automatisch'] = false;
            $leistungen['daten']['eintraege'] = array_map(static fn($l) => [
                'titel' => (string) ($l['titel'] ?? ''), 'text' => (string) ($l['text'] ?? ''),
                'preis' => (string) ($l['preis'] ?? ''), 'dauer' => (string) ($l['dauer'] ?? ''),
            ], (array) $j['leistungen']);
        }

        $stimmen = Bloecke::neu('testimonials');
        $stimmen['daten']['eintraege'] = array_map(static fn($s) => [
            'text' => (string) ($s['text'] ?? ''), 'name' => (string) ($s['name'] ?? ''),
            'zusatz' => (string) ($s['zusatz'] ?? ''), 'sterne' => 5,
        ], (array) ($j['stimmen'] ?? []));

        $faq = Bloecke::neu('faq');
        $faq['daten']['eintraege'] = array_map(static fn($f) => [
            'frage' => (string) ($f['frage'] ?? ''), 'antwort' => (string) ($f['antwort'] ?? ''),
        ], (array) ($j['faq'] ?? []));

        $cta = Bloecke::neu('cta');
        foreach (['titel', 'text', 'knopf_text'] as $feld) {
            if (!empty($j['cta'][$feld])) {
                $cta['daten'][$feld] = (string) $j['cta'][$feld];
            }
        }

        return [
            'bloecke' => [$hero, Bloecke::neu('zahlen'), $spalten, $leistungen, $stimmen,
                          Bloecke::neu('buchung'), $faq, $cta, Bloecke::neu('kontakt')],
            'seo' => [
                'titel' => (string) ($j['seo']['titel'] ?? $name),
                'beschreibung' => (string) ($j['seo']['beschreibung'] ?? ''),
            ],
            'hinweis' => 'Entwurf erstellt.',
        ];
    }

    /* ------------------------------------------------- Trainingsplan -- */

    /**
     * Vierwöchiger Plan aus der Übungsbibliothek, gewichtet nach Schwäche.
     *
     * @return array{name:string,ziel:string,wochen:int,einheiten:array,hinweis:string}
     */
    public static function trainingsplan(string $vorgabe, int $wochen = 4, int $proWoche = 2): array
    {
        $t = mb_strtolower($vorgabe);
        $schwaeche = match (true) {
            str_contains($t, 'putt')                          => 'putting',
            str_contains($t, 'chip')                          => 'chipping',
            str_contains($t, 'pitch')                         => 'pitching',
            str_contains($t, 'bunker') || str_contains($t, 'sand') => 'bunker',
            str_contains($t, 'driver') || str_contains($t, 'abschlag') || str_contains($t, 'länge') => 'holz',
            str_contains($t, 'eisen')                         => 'eisen',
            str_contains($t, 'mental') || str_contains($t, 'druck') || str_contains($t, 'nerv') => 'mental',
            str_contains($t, 'fitness') || str_contains($t, 'beweglich') => 'fitness',
            str_contains($t, 'kurzspiel')                     => 'kurzspiel',
            default                                           => 'allgemein',
        };
        $hcp = preg_match('/hcp\s*(\d+)|handicap\s*(\d+)/u', $t, $m)
            ? (int) ($m[1] ?: $m[2]) : 0;
        $niveau = $hcp === 0 ? 'anfaenger' : ($hcp >= 36 ? 'anfaenger' : ($hcp >= 15 ? 'mittel' : 'fortgeschritten'));

        /* Übungen einsammeln: Schwerpunkt zuerst, dann Ergänzung. */
        $schwerpunkt = Training::uebungen(['kategorie' => $schwaeche === 'allgemein' ? 'eisen' : $schwaeche]);
        $ergaenzung  = [];
        foreach (['putting', 'chipping', 'eisen', 'fitness', 'mental', 'kurzspiel'] as $kat) {
            if ($kat === $schwaeche) {
                continue;
            }
            foreach (Training::uebungen(['kategorie' => $kat]) as $u) {
                $ergaenzung[] = $u;
            }
        }
        if ($schwerpunkt === [] && $ergaenzung === []) {
            return ['name' => '', 'ziel' => '', 'wochen' => $wochen, 'einheiten' => [],
                    'hinweis' => 'In der Übungsbibliothek ist noch nichts hinterlegt. Lege zuerst Übungen an.'];
        }

        $einheiten = [];
        $si = 0;
        $ei = 0;
        for ($woche = 1; $woche <= $wochen; $woche++) {
            for ($einheit = 1; $einheit <= $proWoche; $einheit++) {
                $liste = [];
                // Zwei Drittel Schwerpunkt, ein Drittel Ergänzung – Fortschritt
                // entsteht durch Wiederholung, nicht durch Abwechslung.
                for ($n = 0; $n < 2; $n++) {
                    if ($schwerpunkt !== []) {
                        $liste[] = $schwerpunkt[$si++ % count($schwerpunkt)];
                    }
                }
                if ($ergaenzung !== []) {
                    $liste[] = $ergaenzung[$ei++ % count($ergaenzung)];
                }
                $einheiten[$woche][$einheit] = array_map(static fn($u) => [
                    'exercise_id'    => (int) $u['id'],
                    'titel'          => (string) $u['titel'],
                    'notiz'          => (string) $u['beschreibung'],
                    'dauer_min'      => (int) $u['dauer_min'],
                    'wiederholungen' => (string) $u['wiederholungen'],
                ], $liste);
            }
        }

        $bezeichnung = Training::KATEGORIEN[$schwaeche] ?? 'Allgemein';
        $ziele = [
            'anfaenger' => 'Sicherer Kontakt und ein verlässlicher Ablauf vor jedem Schlag.',
            'mittel' => 'Weniger Streuung und drei Putts weniger pro Runde.',
            'fortgeschritten' => 'Scoring verbessern: Scrambling-Quote und Distanzkontrolle im Kurzspiel.',
        ];

        return [
            'name'   => $wochen . '-Wochen-Plan · Schwerpunkt ' . $bezeichnung,
            'ziel'   => $ziele[$niveau] . ' Schwerpunkt: ' . $bezeichnung . '.',
            'wochen' => $wochen,
            'niveau' => $niveau,
            'einheiten' => $einheiten,
            'hinweis' => self::verfuegbar()
                ? 'Vorschlag erstellt. Bitte vor dem Versenden prüfen und anpassen.'
                : 'Vorschlag aus deiner Übungsbibliothek zusammengestellt. Bitte prüfen und anpassen.',
        ];
    }

    /* --------------------------------------------- Inhalte & Marketing */

    /** @return array<int,array{titel:string,auszug:string}> */
    public static function blogideen(int $anzahl = 6): array
    {
        $vorrat = [
            ['Die drei häufigsten Fehler beim Putten – und wie du sie abstellst',
             'Zu schneller Rückschwung, wandernder Kopf, fehlende Routine. Drei Übungen, die in zehn Minuten Wirkung zeigen.'],
            ['Platzreife: Was dich tatsächlich erwartet',
             'Ablauf, Kosten, Dauer und was niemand vorher sagt – ein ehrlicher Überblick für Einsteiger.'],
            ['Wintertraining: So verlierst du in der Pause nichts',
             'Vier Übungen für Wohnzimmer und Halle, die im Frühjahr den Unterschied machen.'],
            ['Welcher Schläger passt zu dir? Ein Leitfaden ohne Verkaufsabsicht',
             'Warum Fitting bei Anfängern sinnvoll ist – und wann es Geldverschwendung wäre.'],
            ['Drei Schläge weniger in einer Saison: der realistische Weg',
             'Wo die meisten Schläge liegen bleiben und wie ein Trainingsplan dort ansetzt.'],
            ['Golfregeln, die im Turnier wirklich vorkommen',
             'Unspielbarer Ball, Wasserhindernis, Provisorischer – die fünf Situationen, die jeder kennen sollte.'],
            ['Was Launch-Monitor-Daten wirklich sagen',
             'Carry, Spin, Smash Factor – welche Zahl du beachten solltest und welche du getrost ignorierst.'],
            ['Mentaltraining: Die Routine vor dem Schlag',
             'Warum immer dieselbe Abfolge unter Druck trägt und wie du dir eine aufbaust.'],
            ['Golf mit Kindern: Wann der richtige Zeitpunkt ist',
             'Ab welchem Alter Training sinnvoll ist und worauf es bei den ersten Stunden ankommt.'],
            ['Kurzspiel schlägt Länge: Wo deine Schläge liegen bleiben',
             'Eine einfache Rechnung zeigt, warum 100 Meter mehr vom Tee weniger bringen als zehn Putts weniger.'],
        ];
        shuffle($vorrat);
        return array_map(static fn($v) => ['titel' => $v[0], 'auszug' => $v[1]], array_slice($vorrat, 0, $anzahl));
    }

    /**
     * Aus einem Beitrag Beiträge für andere Kanäle machen.
     *
     * @return array<string,string>
     */
    public static function socialMedia(string $titel, string $text): array
    {
        $kern = Util::kuerzen(strip_tags($text), 240);
        $satz = Util::kuerzen(strip_tags($text), 110);

        if (self::verfuegbar()) {
            $json = self::modellJson(
                'Du schreibst Social-Media-Beiträge für einen Golf Professional. Antworte nur mit JSON.',
                "Beitrag: \"{$titel}\"\n\n{$kern}\n\n"
                . 'Erstelle: {"instagram":"","facebook":"","linkedin":"","newsletter":"","youtube":"","kurzvideo":""}'
                . "\nDeutsch, Du-Ansprache, sachlich, keine Übertreibung. Instagram mit 3-5 passenden Hashtags. "
                . "Kurzvideo als Skript mit Szenenangaben."
            );
            if ($json !== null) {
                return array_map('strval', $json);
            }
        }

        return [
            'instagram' => $titel . "\n\n" . $satz . "\n\nMehr dazu im Blog – Link in der Bio.\n\n"
                         . '#golf #golftraining #golfpro #golfschwung #platzreife',
            'facebook'  => $titel . "\n\n" . $kern . "\n\nDen ganzen Beitrag liest du auf meiner Website. "
                         . "Fragen dazu? Schreib sie gern in die Kommentare.",
            'linkedin'  => $titel . "\n\n" . $kern . "\n\nIn meiner Arbeit als Golf Professional sehe ich das "
                         . "regelmäßig – und es ist fast immer schneller zu beheben, als die Betroffenen denken.\n\n"
                         . "#Golf #Coaching #Sport",
            'newsletter'=> "Hallo {vorname},\n\n" . $satz . "\n\n" . $kern
                         . "\n\nWenn du daran arbeiten möchtest: Meine freien Termine findest du auf der Website.\n\n"
                         . "Sportliche Grüße",
            'youtube'   => $titel . "\n\n" . $kern . "\n\nKapitel:\n0:00 Einleitung\n0:45 Das Problem\n"
                         . "2:10 Die Übung\n4:30 Häufige Fehler\n\nMehr Training und Termine auf meiner Website.",
            'kurzvideo' => "SZENE 1 (0–3 Sek): Nahaufnahme des Problems am Ball.\n"
                         . "Text im Bild: \"" . Util::kuerzen($titel, 40) . "\"\n\n"
                         . "SZENE 2 (3–12 Sek): Die Übung in voller Länge zeigen, ohne Schnitt.\n"
                         . "Sprechtext: \"" . $satz . "\"\n\n"
                         . "SZENE 3 (12–20 Sek): Vorher-nachher nebeneinander.\n"
                         . "Sprechtext: \"Drei Minuten am Tag reichen.\"\n\n"
                         . "SZENE 4 (20–25 Sek): Abbinder mit Gesicht in die Kamera.\n"
                         . "Sprechtext: \"Wenn du daran arbeiten willst – Termine auf meiner Seite.\"",
        ];
    }

    /** Landingpage aus einem Satz. */
    public static function landingpage(string $prompt): array
    {
        $entwurf = self::websiteEntwurf($prompt);
        $bloecke = $entwurf['bloecke'];

        // Eine Landingpage hat genau ein Ziel: keine Ablenkung durch
        // Blog, Team oder Kontaktformular am Ende.
        $bloecke = array_values(array_filter($bloecke, static fn($b) =>
            !in_array((string) ($b['typ'] ?? ''), ['kontakt', 'blog', 'team'], true)));

        $preise = Bloecke::neu('preise');
        $preise['daten']['titel'] = 'Das Angebot';
        $preise['daten']['eintraege'] = [[
            'titel' => 'Kurs komplett', 'preis' => '349 €', 'zusatz' => 'inkl. Material',
            'merkmale' => "8 Einheiten à 90 Minuten\nLeihschläger und Bälle\nPrüfungsgebühr enthalten\nKleine Gruppe (max. 6)",
            'knopf_text' => 'Platz sichern', 'knopf_url' => '#buchung', 'hervorheben' => true,
        ]];
        array_splice($bloecke, 4, 0, [$preise]);

        return ['bloecke' => $bloecke, 'seo' => $entwurf['seo'], 'hinweis' => $entwurf['hinweis']];
    }

    /** Kundenanalyse in Prosa – aus echten Zahlen. */
    public static function kundenAnalyse(int $kundeId): string
    {
        $kunde = Tenant::find('customers', $kundeId);
        if (!$kunde) {
            return '';
        }
        $z = Customers::kennzahlen($kundeId);
        $score = Customers::scoreBerechnen($kundeId);
        [, $stufeName] = Customers::stufe($score['score']);

        $letzte = (string) DB::value(
            "SELECT MAX(start) FROM bookings WHERE workspace_id = :w AND customer_id = :k
             AND status IN ('bestaetigt','erschienen') AND start <= :jetzt",
            ['w' => Tenant::id(), 'k' => $kundeId, 'jetzt' => Util::jetzt()], ''
        );
        $kommend = Tenant::count('bookings', "customer_id = :k AND start >= :jetzt AND status != 'abgesagt'",
            ['k' => $kundeId, 'jetzt' => Util::jetzt()]);

        $text = Customers::name($kunde) . ' ist seit ' . Util::datum((string) $kunde['erstellt'])
              . ' dabei, hat ' . $z['termine'] . ' Termine wahrgenommen und ' . Util::geld($z['umsatz'])
              . ' Umsatz gebracht. Der Gesundheitswert liegt bei ' . $score['score'] . ' von 100 – '
              . mb_strtolower($stufeName) . ".\n\n";

        if ($letzte !== '') {
            $tage = Util::tageSeit($letzte);
            $text .= 'Der letzte Termin war vor ' . $tage . ' Tag' . ($tage === 1 ? '' : 'en') . '. ';
        } else {
            $text .= 'Es gab noch keinen wahrgenommenen Termin. ';
        }
        $text .= $kommend > 0
            ? 'Ein nächster Termin ist vereinbart.'
            : 'Ein nächster Termin steht **nicht** an.';

        if ($z['einheiten'] > 0) {
            $text .= "\n\nOffenes Guthaben: " . $z['einheiten'] . ' Einheit' . ($z['einheiten'] === 1 ? '' : 'en')
                   . ($kommend === 0 ? ' – ohne vereinbarten Termin. Das ist der naheliegendste Anlass für einen Anruf.' : '.');
        }
        if ($z['offen'] > 0) {
            $text .= "\n\nOffene Rechnungen: " . Util::geld($z['offen']) . '.';
        }

        $verlauf = Training::entwicklung($kundeId, 'hcp');
        if ($verlauf !== null) {
            $text .= "\n\nHandicap-Entwicklung: aktuell " . Util::zahl($verlauf['aktuell'], 1)
                   . ', im Schnitt der früheren Einträge ' . Util::zahl($verlauf['schnitt'], 1) . ' – '
                   . ($verlauf['besser'] ? 'die Richtung stimmt.' : 'zuletzt keine Verbesserung.');
        }

        $text .= "\n\n**Vorschlag:** ";
        if ($z['einheiten'] > 0 && $kommend === 0) {
            $text .= 'Terminvorschlag für die offenen Einheiten schicken.';
        } elseif ($letzte !== '' && Util::tageSeit($letzte) > 60) {
            $text .= 'Persönlich melden – nach zwei Monaten Pause ist die Hemmschwelle am höchsten.';
        } elseif ($score['score'] >= 70) {
            $text .= 'Läuft gut. Ein Paket oder ein Kurs wäre hier der nächste sinnvolle Schritt.';
        } else {
            $text .= 'Nach dem Trainingsziel fragen – oft fehlt einfach ein konkreter Anlass zum Wiederkommen.';
        }
        return $text;
    }

    /** Erklärt eine Kennzahlenlage in zwei, drei Sätzen. */
    public static function diagrammErklaeren(string $was, array $kennzahlen): string
    {
        $umsatz = $kennzahlen['umsatz'] ?? null;
        $teile = [];

        if ($umsatz && $umsatz['delta'] !== null) {
            $richtung = $umsatz['delta'] > 0 ? 'gestiegen' : 'gesunken';
            $teile[] = 'Der Umsatz ist gegenüber dem Vergleichszeitraum um '
                     . Util::zahl(abs($umsatz['delta']), 1) . ' Prozent ' . $richtung
                     . ' – von ' . Util::geld((int) $umsatz['vorher']) . ' auf ' . Util::geld((int) $umsatz['wert']) . '.';
        }
        if (!empty($kennzahlen['buchungen'])) {
            $b = $kennzahlen['buchungen'];
            $teile[] = 'Dahinter stehen ' . Util::zahl($b['wert']) . ' Buchungen'
                     . ($b['delta'] !== null
                        ? ' (' . ($b['delta'] > 0 ? '+' : '') . Util::zahl($b['delta'], 1) . ' Prozent)' : '') . '.';
        }
        if (!empty($kennzahlen['warenkorb']['wert'])) {
            $teile[] = 'Der durchschnittliche Bestellwert liegt bei '
                     . Util::geld((int) $kennzahlen['warenkorb']['wert']) . '.';
        }
        if (!empty($kennzahlen['conversion']) && $kennzahlen['conversion']['wert'] > 0) {
            $teile[] = 'Von den Website-Besuchern werden ' . Util::prozent($kennzahlen['conversion']['wert'], 1)
                     . ' zu einer Anfrage.';
        }

        if ($teile === []) {
            return 'Für diesen Zeitraum liegen noch zu wenige Daten vor, um eine Aussage zu treffen.';
        }

        $schluss = '';
        if ($umsatz && $umsatz['delta'] !== null && $umsatz['delta'] < -10) {
            $schluss = "\n\nDer Rückgang ist deutlich. Ein Blick auf die Auslastung und die inaktiven Kunden "
                     . 'zeigt meist, woran es liegt.';
        } elseif ($umsatz && $umsatz['delta'] !== null && $umsatz['delta'] > 15) {
            $schluss = "\n\nGutes Wachstum. Jetzt lohnt der Blick darauf, ob die Kapazität mitwächst – "
                     . 'sonst wird der Kalender zur Bremse.';
        }
        return implode(' ', $teile) . $schluss;
    }

    /* ===================================================== Modellzugang */

    private static function systemText(): string
    {
        return 'Du bist der Assistent in TeePilot, der Software eines Golf Professionals. '
             . 'Antworte auf Deutsch, kurz und konkret, in der Du-Form. '
             . 'Erfinde niemals Zahlen: Wenn dir eine Zahl fehlt, sage, wo sie in der Software steht. '
             . 'Du gibst keine rechtliche, steuerliche oder medizinische Beratung. '
             . 'Du führst keine Aktionen aus – du schlägst vor, entscheiden tut der Mensch.';
    }

    /** Ein knapper Auszug der Lage, damit das Modell nicht raten muss. */
    private static function kontext(): string
    {
        $z = Analytics::zeitraum('monat');
        return implode("\n", [
            'Name: ' . Tenant::name(),
            'Aktive Kunden: ' . Tenant::count('customers', "status = 'aktiv'"),
            'Umsatz diesen Monat: ' . Util::geld(Commerce::umsatz($z['von'], $z['bis'])),
            'Termine diese Woche: ' . count(Bookings::zeitraum(
                date('Y-m-d', strtotime('monday this week')) . ' 00:00:00',
                date('Y-m-d', strtotime('sunday this week')) . ' 23:59:59')),
            'Offene Anfragen: ' . Tenant::count('leads', "stufe NOT IN ('kunde','verloren')"),
            'Offene Rechnungen: ' . Util::geld(Invoices::offenerBetrag()),
            'Leistungen: ' . implode(', ', array_map(
                static fn($s) => $s['name'] . ' (' . Util::geldKurz((int) $s['preis_cent']) . ')',
                Tenant::all('services', 'aktiv = 1', [], 'position', 8))),
        ]);
    }

    /** @param array<int,array{rolle:string,text:string}> $verlauf */
    public static function modell(string $system, array $verlauf): string
    {
        if (!self::verfuegbar()) {
            return '';
        }
        $nachrichten = array_map(static fn($n) => [
            'role' => $n['rolle'] === 'assistant' ? 'assistant' : 'user',
            'content' => $n['text'],
        ], $verlauf);

        $antwort = self::anfrage([
            'model'      => (string) Config::get('ai.model', 'claude-sonnet-5'),
            'max_tokens' => 1400,
            'system'     => $system,
            'messages'   => $nachrichten,
        ]);
        if (!isset($antwort['content'][0]['text'])) {
            return '';
        }
        return trim((string) $antwort['content'][0]['text']);
    }

    private static function modellJson(string $system, string $frage): ?array
    {
        $text = self::modell($system, [['rolle' => 'user', 'text' => $frage]]);
        if ($text === '') {
            return null;
        }
        if (preg_match('/\{.*\}/s', $text, $t)) {
            $daten = json_decode($t[0], true);
            return is_array($daten) ? $daten : null;
        }
        return null;
    }

    private static function anfrage(array $nutzlast): array
    {
        $ch = curl_init('https://api.anthropic.com/v1/messages');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => Util::json($nutzlast),
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'x-api-key: ' . (string) Config::get('ai.api_key', ''),
                'anthropic-version: 2023-06-01',
            ],
        ]);
        $antwort = curl_exec($ch);
        curl_close($ch);

        if ($antwort === false) {
            return [];
        }
        $daten = json_decode((string) $antwort, true);
        return is_array($daten) ? $daten : [];
    }

    /* ------------------------------------------------------ Vorschläge */

    /**
     * Ein KI-Vorschlag wird gespeichert, nicht ausgeführt. Erst die
     * Bestätigung durch einen Menschen macht daraus eine Änderung.
     */
    public static function vorschlagSpeichern(string $art, string $titel, string $text, array $nutzdaten = [],
                                              string $objekt = '', int $objektId = 0): int
    {
        return Tenant::insert('ai_suggestions', [
            'art' => $art, 'titel' => $titel, 'text' => $text,
            'nutzdaten' => Util::json($nutzdaten),
            'objekt' => $objekt, 'objekt_id' => $objektId,
            'status' => 'offen', 'user_id' => Auth::id(),
        ]);
    }

    public static function vorschlagEntscheiden(int $id, bool $annehmen): void
    {
        Tenant::update('ai_suggestions', $id, [
            'status' => $annehmen ? 'angenommen' : 'abgelehnt',
            'entschieden' => Util::jetzt(),
        ]);
        Audit::schreiben($annehmen ? 'ki_angenommen' : 'ki_abgelehnt', 'ai_suggestion', $id);
    }
}
