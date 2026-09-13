<?php
/**
 * Befehle – was die Befehlspalette (Strg/Cmd + K) anbietet.
 *
 * Zwei Sorten: Sprünge in Bereiche und Handlungen („Neuen Kunden anlegen").
 * Beide werden nach Rechten gefiltert – die Palette darf nichts anbieten,
 * was die Seite danach verweigert.
 */
final class Befehle
{
    /** @return array<int,array<string,mixed>> */
    public static function liste(): array
    {
        $b = [];

        /* Handlungen zuerst: Wer die Palette öffnet, will meistens etwas tun. */
        $handlungen = [
            ['Neuen Kunden anlegen', '/app/kunde.php?id=neu', 'user-plus', 'customers.write', 'kunde kunden anlegen neu erstellen person'],
            ['Termin buchen', '/app/buchung.php?id=neu', 'calendar', 'bookings.write', 'termin buchung buchen stunde training'],
            ['Rechnung erstellen', '/app/rechnung.php?id=neu', 'invoices', 'invoices.write', 'rechnung faktura beleg erstellen'],
            ['Lead erfassen', '/app/lead.php?id=neu', 'leads', 'leads.write', 'lead anfrage interessent'],
            ['Trainingsplan erstellen', '/app/trainingsplan.php?id=neu', 'training', 'training.write', 'trainingsplan plan uebung woche'],
            ['Landingpage erstellen', '/app/seite.php?id=neu&art=landingpage', 'website', 'website.write', 'landingpage seite kampagne'],
            ['Seite erstellen', '/app/seite.php?id=neu', 'website', 'website.write', 'seite website neu'],
            ['Beitrag schreiben', '/app/beitrag.php?id=neu', 'content', 'content.write', 'blog beitrag artikel schreiben'],
            ['Produkt anlegen', '/app/produkt.php?id=neu', 'products', 'products.write', 'produkt paket verkaufen shop'],
            ['Gutschein ausstellen', '/app/gutscheine.php?aktion=neu', 'gift', 'products.write', 'gutschein geschenk voucher'],
            ['Event anlegen', '/app/event.php?id=neu', 'events', 'events.write', 'event workshop camp turnier reise'],
            ['Newsletter schreiben', '/app/kampagne.php?id=neu', 'newsletter', 'newsletter.write', 'newsletter mail kampagne versenden'],
            ['Video hochladen', '/app/videos.php?aktion=neu', 'video', 'video.write', 'video schwung analyse hochladen'],
            ['KI-Assistent öffnen', '/app/ki.php', 'ai', 'ai.use', 'ki ai assistent fragen chat'],
        ];
        foreach ($handlungen as [$titel, $url, $icon, $recht, $worte]) {
            if (Auth::darf($recht)) {
                $b[] = [
                    'titel' => $titel, 'url' => App::url($url), 'gruppe' => 'Aktionen',
                    'icon' => Icon::svg($icon, 17), 'schlagworte' => $worte, 'start' => true,
                ];
            }
        }

        /* Sprünge in die Bereiche */
        $ziele = [
            'dashboard' => '/app/', 'customers' => '/app/kunden.php', 'leads' => '/app/leads.php',
            'calendar' => '/app/kalender.php', 'bookings' => '/app/buchungen.php',
            'training' => '/app/training.php', 'video' => '/app/videos.php', 'courses' => '/app/kurse.php',
            'products' => '/app/produkte.php', 'payments' => '/app/zahlungen.php', 'invoices' => '/app/rechnungen.php',
            'website' => '/app/website.php', 'content' => '/app/inhalte.php', 'events' => '/app/events.php',
            'marketing' => '/app/marketing.php', 'newsletter' => '/app/newsletter.php',
            'automations' => '/app/automationen.php', 'community' => '/app/community.php',
            'analytics' => '/app/auswertung.php', 'ai' => '/app/ki.php', 'settings' => '/app/einstellungen.php',
        ];
        foreach ($ziele as $key => $url) {
            if (!Module::istKern($key) && !Tenant::modul($key)) {
                continue;
            }
            if (!Auth::darf('modul.' . $key)) {
                continue;
            }
            $info = Module::info($key) ?? [];
            $b[] = [
                'titel' => (string) ($info['name'] ?? $key),
                'url' => App::url($url),
                'gruppe' => 'Bereiche',
                'icon' => Icon::svg((string) ($info['icon'] ?? 'info'), 17),
                'schlagworte' => strtolower(((string) ($info['en'] ?? '')) . ' ' . ($info['beschreibung'] ?? '')),
            ];
        }

        /* Werkzeuge */
        $werkzeuge = [
            ['Website ansehen', '/app/vorschau.php', 'eye', 'modul.website', 'vorschau website ansehen live'],
            ['Design und Farben', '/app/design.php', 'palette', 'website.write', 'design farben schrift logo branding'],
            ['SEO prüfen', '/app/seo.php', 'target', 'website.write', 'seo suchmaschine google score'],
            ['Verfügbarkeiten', '/app/verfuegbarkeit.php', 'clock', 'bookings.write', 'arbeitszeiten urlaub sperrzeit verfuegbarkeit'],
            ['Leistungen und Preise', '/app/leistungen.php', 'list', 'bookings.write', 'leistungen preise services angebot'],
            ['Pakete', '/app/pakete.php', 'ticket', 'bookings.write', 'pakete einheiten 10er karte'],
            ['Team und Rollen', '/app/team.php', 'customers', 'settings.team', 'team trainer mitarbeiter rollen rechte'],
            ['Standorte', '/app/standorte.php', 'pin', 'settings.allgemein', 'standort club range indoor platz'],
            ['Protokoll', '/app/protokoll.php', 'shield', 'audit.view', 'protokoll audit log sicherheit'],
            ['Tarif und Module', '/app/tarif.php', 'layers', 'settings.allgemein', 'tarif plan module abo upgrade'],
            ['Datenschutz und Export', '/app/datenschutz.php', 'lock', 'settings.recht', 'dsgvo datenschutz export loeschung einwilligung'],
        ];
        foreach ($werkzeuge as [$titel, $url, $icon, $recht, $worte]) {
            if (Auth::darf($recht)) {
                $b[] = [
                    'titel' => $titel, 'url' => App::url($url), 'gruppe' => 'Werkzeuge',
                    'icon' => Icon::svg($icon, 17), 'schlagworte' => $worte,
                ];
            }
        }

        return $b;
    }
}
