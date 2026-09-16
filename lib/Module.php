<?php
/**
 * Module – das Verzeichnis aller Bereiche und die Regel, wer was sieht.
 *
 * Hier steht die wichtigste Produktentscheidung als Code: **einfach im
 * Standard, mächtig auf Wunsch.** Sechs Bereiche sind Kern und immer da.
 * Alles Weitere ist ein Modul, das der Pro einschaltet, wenn er es braucht.
 * Ein Einsteiger sieht acht Menüpunkte, keine zweiundzwanzig.
 *
 * Der Tarif legt fest, was einschaltbar *ist*; die Einstellung des Workspace,
 * was tatsächlich eingeschaltet *ist*. Beides wird getrennt gehalten, damit
 * ein Tarifwechsel keine Konfiguration zerstört.
 */
final class Module
{
    /** Immer sichtbar – ohne diese sechs ist das Produkt kein Produkt. */
    public const KERN = ['dashboard', 'website', 'customers', 'calendar', 'bookings', 'settings'];

    /**
     * Reihenfolge hier = Reihenfolge im Menü.
     * gruppe steuert die Zwischenüberschrift, plan die Mindeststufe.
     */
    private const LISTE = [
        'dashboard' => [
            'name' => 'Dashboard', 'icon' => 'dashboard', 'gruppe' => '', 'plan' => 'starter',
            'beschreibung' => 'Zahlen, Termine und Empfehlungen des Tages auf einen Blick.',
        ],

        'customers' => [
            'name' => 'Kunden', 'en' => 'Customers', 'icon' => 'customers', 'gruppe' => 'kunden', 'plan' => 'starter',
            'beschreibung' => 'Kundenakte mit HCP, Historie, Paketen und Kommunikation.',
        ],
        'leads' => [
            'name' => 'Leads', 'icon' => 'leads', 'gruppe' => 'kunden', 'plan' => 'pro',
            'beschreibung' => 'Anfragen von der Website bis zum Kunden begleiten.',
        ],
        'calendar' => [
            'name' => 'Kalender', 'en' => 'Calendar', 'icon' => 'calendar', 'gruppe' => 'kunden', 'plan' => 'starter',
            'beschreibung' => 'Wochen- und Tagesansicht über alle Trainer und Standorte.',
        ],
        'bookings' => [
            'name' => 'Buchungen', 'en' => 'Bookings', 'icon' => 'bookings', 'gruppe' => 'kunden', 'plan' => 'starter',
            'beschreibung' => 'Leistungen, Verfügbarkeiten, Pakete und Online-Buchung.',
        ],

        'training' => [
            'name' => 'Training', 'icon' => 'training', 'gruppe' => 'training', 'plan' => 'pro',
            'beschreibung' => 'Trainingspläne, Übungsbibliothek und Leistungsdaten.',
        ],
        'video' => [
            'name' => 'Videoanalyse', 'en' => 'Video Analysis', 'icon' => 'video', 'gruppe' => 'training', 'plan' => 'business',
            'beschreibung' => 'Schwunganalyse mit Zeichenwerkzeugen und Einzelbildschritt.',
        ],
        'courses' => [
            'name' => 'Kurse', 'en' => 'Courses', 'icon' => 'courses', 'gruppe' => 'training', 'plan' => 'pro',
            'beschreibung' => 'Online-Kurse mit Modulen, Lektionen, Quiz und Zertifikat.',
        ],

        'products' => [
            'name' => 'Produkte', 'en' => 'Products', 'icon' => 'products', 'gruppe' => 'verkauf', 'plan' => 'pro',
            'beschreibung' => 'Pakete, Gutscheine, Kurse und Merchandise verkaufen.',
        ],
        'payments' => [
            'name' => 'Zahlungen', 'en' => 'Payments', 'icon' => 'payments', 'gruppe' => 'verkauf', 'plan' => 'pro',
            'beschreibung' => 'Kartenzahlung, SEPA, Apple Pay und Abos über Stripe.',
        ],
        'invoices' => [
            'name' => 'Rechnungen', 'en' => 'Invoices', 'icon' => 'invoices', 'gruppe' => 'verkauf', 'plan' => 'pro',
            'beschreibung' => 'Rechnungen, Gutschriften und offene Posten.',
        ],

        'website' => [
            'name' => 'Website', 'icon' => 'website', 'gruppe' => 'web', 'plan' => 'starter',
            'beschreibung' => 'Baukasten, Seiten, Landingpages und Design.',
        ],
        'content' => [
            'name' => 'Inhalte', 'en' => 'Content', 'icon' => 'content', 'gruppe' => 'web', 'plan' => 'starter',
            'beschreibung' => 'Blog, Beiträge, Kategorien und Mediathek.',
        ],
        'events' => [
            'name' => 'Events', 'icon' => 'events', 'gruppe' => 'web', 'plan' => 'pro',
            'beschreibung' => 'Workshops, Camps, Turniere und Gruppentrainings.',
        ],
        'travel' => [
            'name' => 'Reisen', 'en' => 'Trips', 'icon' => 'globe', 'gruppe' => 'web', 'plan' => 'pro',
            'beschreibung' => 'Golfreisen mit Hotel, Zimmerwahl, Anzahlung und Anmeldung.',
        ],

        'marketing' => [
            'name' => 'Marketing', 'icon' => 'marketing', 'gruppe' => 'wachstum', 'plan' => 'pro',
            'beschreibung' => 'Kampagnen, Formulare und Social-Media-Inhalte.',
        ],
        'newsletter' => [
            'name' => 'Newsletter', 'icon' => 'newsletter', 'gruppe' => 'wachstum', 'plan' => 'pro',
            'beschreibung' => 'Newsletter bauen, segmentieren, planen und auswerten.',
        ],
        'automations' => [
            'name' => 'Automationen', 'en' => 'Automations', 'icon' => 'automations', 'gruppe' => 'wachstum', 'plan' => 'business',
            'beschreibung' => 'Abläufe aus Auslöser, Bedingung, Wartezeit und Aktion.',
        ],
        'community' => [
            'name' => 'Community', 'icon' => 'community', 'gruppe' => 'wachstum', 'plan' => 'business',
            'beschreibung' => 'Gruppen, Beiträge, Challenges und Ranglisten.',
        ],

        'analytics' => [
            'name' => 'Auswertung', 'en' => 'Analytics', 'icon' => 'analytics', 'gruppe' => 'wissen', 'plan' => 'business',
            'beschreibung' => 'Umsatz, Auslastung, Retention und Websitezahlen.',
        ],
        'ai' => [
            'name' => 'KI-Assistent', 'en' => 'AI Assistant', 'icon' => 'ai', 'gruppe' => 'wissen', 'plan' => 'business',
            'beschreibung' => 'Fragen in normaler Sprache, Empfehlungen, Textentwürfe.',
        ],

        'settings' => [
            'name' => 'Einstellungen', 'en' => 'Settings', 'icon' => 'settings', 'gruppe' => 'system', 'plan' => 'starter',
            'beschreibung' => 'Profil, Team, Standorte, Zahlungen, Recht und Tarif.',
        ],
    ];

    private const GRUPPEN = [
        'kunden'   => 'Kunden & Termine',
        'training' => 'Training',
        'verkauf'  => 'Verkauf',
        'web'      => 'Website',
        'wachstum' => 'Wachstum',
        'wissen'   => 'Wissen',
        'system'   => '',
    ];

    private const PLAN_RANG = ['starter' => 1, 'pro' => 2, 'business' => 3, 'academy' => 4];

    /**
     * Unterpunkte: eigener Eintrag im Menü, aber kein eigenes Modul.
     *
     * „Pakete" gehört zu den Buchungen – es teilt deren Recht, deren
     * Tarifstufe und deren Ein-/Ausschalter. Ein eigenes Modul daraus zu
     * machen hieße, es einzeln abschaltbar zu machen; Pakete ohne
     * Buchungen ergeben aber keinen Sinn, und der Tarifbildschirm bekäme
     * einen Schalter, den niemand versteht.
     *
     * Der Grund für den eigenen Eintrag: Die Seite gab es längst, sie war
     * nur über einen Knopf in der Buchungsliste zu erreichen. Wer dort
     * nie hinsieht, sucht das Feld „Einheiten" vergeblich unter Produkte
     * – und findet es dort nicht, weil es dort keins gibt.
     *
     * `nach` nennt den Hauptpunkt: hinter ihm steht der Eintrag im Menü,
     * von ihm erbt er Modul und Recht.
     */
    private const UNTERPUNKTE = [
        'packages' => [
            'name' => 'Pakete', 'en' => 'Packages', 'icon' => 'ticket',
            'gruppe' => 'kunden', 'plan' => 'starter', 'nach' => 'bookings',
            'beschreibung' => 'Zehnerkarten und Guthaben: anlegen, verkaufen, verbrauchen.',
        ],
    ];

    /** @return array<string,array<string,mixed>> */
    public static function alle(): array
    {
        return self::LISTE;
    }

    /**
     * @return array<string,mixed>|null
     *
     * Findet auch Unterpunkte. `alle()` dagegen bleibt bei den Modulen –
     * der Tarifbildschirm zählt damit ab, was sich ein- und ausschalten
     * lässt, und ein Unterpunkt gehört dort nicht hinein.
     */
    public static function info(string $key): ?array
    {
        return self::LISTE[$key] ?? self::UNTERPUNKTE[$key] ?? null;
    }

    public static function name(string $key): string
    {
        return (string) (self::info($key)['name'] ?? ucfirst($key));
    }

    public static function icon(string $key): string
    {
        return (string) (self::info($key)['icon'] ?? 'info');
    }

    public static function gruppenname(string $gruppe): string
    {
        return self::GRUPPEN[$gruppe] ?? '';
    }

    public static function istKern(string $key): bool
    {
        return in_array($key, self::KERN, true);
    }

    /** Erlaubt der Tarif dieses Modul überhaupt? */
    public static function imPlan(string $key, string $plan): bool
    {
        $noetig = (string) (self::LISTE[$key]['plan'] ?? 'starter');
        return (self::PLAN_RANG[$plan] ?? 1) >= (self::PLAN_RANG[$noetig] ?? 1);
    }

    /** Was ein frischer Workspace in diesem Tarif eingeschaltet bekommt. */
    public static function standardFuerPlan(string $plan): array
    {
        $an = self::KERN;
        // Bewusst sparsam: Ein Einsteiger soll nicht von 22 Punkten erschlagen
        // werden. Alles andere ist einen Klick entfernt.
        foreach (['content', 'leads', 'products', 'invoices', 'training'] as $key) {
            if (self::imPlan($key, $plan)) {
                $an[] = $key;
            }
        }
        if ($plan === 'business' || $plan === 'academy') {
            foreach (['payments', 'analytics', 'ai', 'courses', 'events', 'travel',
                      'newsletter', 'marketing', 'video', 'automations'] as $key) {
                $an[] = $key;
            }
        }
        return array_values(array_unique($an));
    }

    /**
     * Das Menü für den angemeldeten Benutzer: nur eingeschaltete Module,
     * nur was die Rolle sehen darf, gruppiert und in fester Reihenfolge.
     *
     * @return array<string,array<int,array<string,mixed>>>
     */
    public static function menue(): array
    {
        $menue = [];
        foreach (self::LISTE as $key => $info) {
            if (!self::istKern($key) && !Tenant::modul($key)) {
                continue;
            }
            if (!Auth::darf('modul.' . $key)) {
                continue;
            }
            $menue[$info['gruppe']][] = ['key' => $key] + $info;

            /* Unterpunkte stehen direkt hinter ihrem Hauptpunkt und sind
               genau dann da, wenn er da ist – geprüft wurde das eine Zeile
               weiter oben, für beide zusammen. */
            foreach (self::UNTERPUNKTE as $unterKey => $unter) {
                if ($unter['nach'] === $key) {
                    $menue[$unter['gruppe']][] = ['key' => $unterKey] + $unter;
                }
            }
        }
        return $menue;
    }

    /** @return array<string,array<string,mixed>> Tarife für die Einstellungen. */
    public static function plaene(): array
    {
        return [
            'starter' => [
                'name' => 'Starter', 'preis_cent' => 2900,
                'zeile' => 'Website und Buchung – alles, um online zu starten.',
                'enthalten' => ['Website-Baukasten', 'Online-Buchung', 'Kundenakte', 'Kalender', 'Blog'],
            ],
            'pro' => [
                'name' => 'Pro', 'preis_cent' => 5900,
                'zeile' => 'Das volle Geschäft: Verkauf, Rechnungen, Marketing.',
                'enthalten' => ['Alles aus Starter', 'Leads & Pipeline', 'Produkte & Pakete', 'Zahlungen', 'Rechnungen', 'Newsletter', 'Trainingspläne', 'Kurse', 'Events'],
            ],
            'business' => [
                'name' => 'Business', 'preis_cent' => 9900,
                'zeile' => 'Mit KI, Videoanalyse und vollständiger Auswertung.',
                'enthalten' => ['Alles aus Pro', 'KI-Assistent', 'Videoanalyse', 'Automationen', 'Auswertung', 'Community', 'Smart Pricing'],
            ],
            'academy' => [
                'name' => 'Academy', 'preis_cent' => 19900,
                'zeile' => 'Für Akademien: mehrere Trainer, Standorte, eigene Marke.',
                'enthalten' => ['Alles aus Business', 'Mehrere Trainer', 'Mehrere Standorte', 'White Label', 'Eigene Domain', 'Rollen & Rechte', 'Vorrangiger Support'],
            ],
        ];
    }

    public static function planName(string $plan): string
    {
        return (string) (self::plaene()[$plan]['name'] ?? ucfirst($plan));
    }
}
