<?php
/**
 * Kundenportal.
 *
 * Der Zugang läuft über einen langen Zufallsschlüssel im Link, den der Pro
 * dem Kunden schickt. Wer ein Passwort setzt, meldet sich danach mit
 * E-Mail und Passwort an – das ist sicherer, aber niemand wird dazu
 * gezwungen: Ein Portal, das erst nach einer Registrierung etwas zeigt,
 * benutzt kaum jemand.
 *
 * Der Schlüssel landet nach dem ersten Aufruf in der Sitzung und
 * verschwindet aus der Adresszeile, damit er nicht in Verläufen und
 * Referrern weiterwandert.
 */
require __DIR__ . '/../lib/bootstrap.php';

/* --------------------------------------------------------- Anmeldung --- */

/*
 * Angemeldet wird über Kundenlogin – dieselbe Klasse, die auch die
 * öffentliche Buchung benutzt.
 *
 * Früher lief das Portal in einer eigenen Sitzung namens „gp_portal",
 * damit ein abmeldender Kunde nicht den Trainer mit abmeldet, der
 * denselben Browser benutzt. Dieses Ziel bleibt, nur mit einem
 * einfacheren Mittel: eine Sitzung, getrennte Schlüssel, und das
 * Abmelden räumt nur die des Kunden weg.
 *
 * Nötig wurde die Umstellung, weil PHP je Aufruf nur eine Sitzung offen
 * hält. Mit zwei Namen wäre ein im Portal angemeldeter Kunde beim Buchen
 * wieder ein Fremder gewesen – und genau das soll er nicht sein.
 */

/*
 * Zuerst der Betrieb – aus eigener Domain oder ?w=. Findet sich keiner,
 * bleibt er offen: Auf einer Installation mit mehreren Golfschulen sagt
 * erst der angemeldete Kunde, um welche es geht. Deshalb hier `false`
 * statt des sonst üblichen 404.
 */
Oeffentlich::mandantSetzen(false);

if (App::get('abmelden') !== '') {
    Kundenlogin::abmelden();
}

/* 1. Schlüssel aus dem Link – danach aus der Adresszeile nehmen. */
$token = App::get('t');
if ($token !== '' && Kundenlogin::mitToken($token)) {
    App::weiter('/portal/');
}

/* 2. Anmeldung mit E-Mail und Passwort */
$fehler = '';
if (!Kundenlogin::angemeldet() && App::istPost() && App::aktion() === 'anmelden') {
    /* Auch die Anmeldung selbst: Sonst meldet eine fremde Seite den
       Besucher unbemerkt im Konto des Angreifers an. */
    Auth::csrfFordern();

    if (Kundenlogin::mitPasswort(App::post('email'), App::postRoh('passwort'))) {
        App::weiter('/portal/');
    }
    /*
     * Eine Fehlermeldung, die nicht verrät, ob es die Adresse gibt –
     * sonst ließe sich das Portal als Kundenverzeichnis missbrauchen.
     * Die Sperre darf dagegen benannt werden: Sie entsteht durch die
     * Versuche des Fragenden, nicht dadurch, dass es das Konto gibt.
     */
    $fehler = Kundenlogin::gebremst(App::post('email'))
        ? 'Zu viele Versuche. Bitte in 15 Minuten erneut probieren.'
        : 'E-Mail oder Passwort stimmt nicht.';
}

/* 3. Bestehende Sitzung */
$kunde = Kundenlogin::kunde();

/* ------------------------------------------------- Anmeldeformular ----- */

if ($kunde === null) {
    /*
     * Steht immer noch kein Betrieb fest – mehrere Golfschulen auf einer
     * Installation, und der Besucher kam ohne ?w= –, dann wird für die
     * Gestaltung der erste genommen. Es geht hier nur um Farbe und Name;
     * wohin die Anmeldung führt, entscheidet gleich der Kunde selbst.
     */
    if (!Tenant::gesetzt()) {
        $erster = Tenant::erster();
        if ($erster !== null) {
            Tenant::setzen((int) $erster['id']);
        }
    }

    require __DIR__ . '/partials/zugang.php';

    zugangSeite('Dein Bereich', 'Termine, Trainingsplan und Unterlagen an einem Ort.', ''
        /* Auf den Inhalt prüfen, nicht auf isset: $fehler ist immer
           gesetzt, nur meistens leer. */
        . ($fehler !== ''
           ? '<div class="hinweis hinweis--gefahr mb-4">' . Icon::svg('alert', 17)
             . '<div class="hinweis__text">' . Util::h($fehler) . '</div></div>'
           : '')
        . '<form method="post" class="karte">'
        . Auth::csrfFeld()
        . '<input type="hidden" name="aktion" value="anmelden">'
        . '<div class="karte__koerper">'
        . '<div class="feld"><label class="feld__label" for="pe">E-Mail</label>'
        . '<input class="eingabe" id="pe" type="email" name="email" required autocomplete="email"></div>'
        . '<div class="feld"><label class="feld__label" for="pp">Passwort</label>'
        . '<input class="eingabe" id="pp" type="password" name="passwort" required'
        . ' autocomplete="current-password"></div>'
        . '<button class="btn btn--primaer btn--voll" type="submit">Anmelden</button>'
        . '</div></form>'
        /*
         * Der Weg zum Konto steht sichtbar darunter, nicht im Kleingedruckten:
         * Wer hier ohne Zugang landet, soll ihn in einem Klick bekommen und
         * nicht erst auf die nächste Terminbestätigung warten.
         */
        . (Kundenlogin::registrierungOffen()
           ? '<p class="zugang__wechsel">Noch kein Konto? '
             . '<a href="' . Util::attr(Oeffentlich::url('/portal/registrieren.php')) . '">Jetzt anlegen</a></p>'
           : '')
        . '<div class="hinweis hinweis--still mt-4">' . Icon::svg('info', 17)
        . '<div class="hinweis__text">Noch kein Passwort? Dein Trainer schickt dir einen persönlichen '
        . 'Link – damit kommst du ohne Anmeldung hinein und kannst dort ein Passwort setzen.</div></div>');
}

/* ---------------------------------------------------------- Aktionen --- */

$ansicht = App::get('ansicht', 'start');

if (App::istPost()) {
    /*
     * Das Merkmal gegen gefälschte Anfragen – im Portal bis hierher nicht
     * geprüft. Ohne diese Zeile nahm eine fremde Seite jeden Vorgang
     * entgegen, den der angemeldete Kunde ausführen darf: Termin absagen,
     * Löschanfrage stellen und, am teuersten, das Passwort setzen. Dass
     * moderne Browser das Kennzeichen bei seitenfremden POSTs ohnehin
     * zurückhalten (SameSite=Lax), ist eine Eigenschaft des Browsers –
     * nicht des Servers. Der Server hatte dazu bisher keine Meinung.
     */
    Auth::csrfFordern();

    $aktion = App::aktion();

    if ($aktion === 'absagen') {
        $b = Tenant::find('bookings', App::postInt('id'));
        $frist = (int) Tenant::einstellung('stornofrist_stunden', 24);
        if ($b === null || (int) $b['customer_id'] !== (int) $kunde['id']) {
            App::melden('Dieser Termin gehört nicht zu deinem Konto.', 'fehler');
        } elseif (strtotime((string) $b['start']) - time() < $frist * 3600) {
            App::melden('Die Absagefrist von ' . $frist . ' Stunden ist vorbei. '
                      . 'Ruf bitte kurz an – oft findet sich trotzdem eine Lösung.', 'fehler');
        } else {
            Bookings::absagen((int) $b['id'], 'Vom Kunden über das Portal abgesagt');
            App::melden('Termin abgesagt. Dein Trainer wurde benachrichtigt.');
        }
        App::weiter('/portal/?ansicht=termine');
    }

    /*
     * Buchen aus dem Kundenbereich heraus.
     *
     * Bisher fuehrte der einzige Weg zu einem neuen Termin ueber die
     * oeffentliche Website - der angemeldete Kunde musste sein Portal
     * verlassen, um das zu tun, wofuer er gekommen ist. Das ist dieselbe
     * Buchung wie in buchen.php, nur ohne alles, was dort dem Fremden
     * gilt: kein Name, keine E-Mail, keine Einwilligung, keine
     * Roboterfalle. Wer hier steht, ist angemeldet und bekannt.
     *
     * Was gleich bleibt, ist die Pruefung: Die Zeit wird gegen den echten
     * Kalender gehalten, bevor gebucht wird. Zwischen dem Anzeigen der
     * freien Zeiten und dem Klick koennen Minuten liegen, und in dieser
     * Zeit kann jemand anders denselben Platz genommen haben.
     */
    if ($aktion === 'buchen') {
        $serviceId = App::postInt('service_id');
        $start     = App::post('start');
        $trainerId = App::postInt('trainer_id');
        $notiz     = trim(App::post('notiz'));
        $service   = Tenant::find('services', $serviceId);
        $zurueck   = '/portal/?ansicht=buchen' . ($serviceId > 0 ? '&service_id=' . $serviceId : '');

        if ($service === null || (int) $service['aktiv'] !== 1 || (int) $service['online_buchbar'] !== 1) {
            App::melden('Diese Leistung ist nicht mehr buchbar.', 'fehler');
            App::weiter('/portal/?ansicht=buchen');
        }

        /*
         * Der Trainer kommt aus der Verfuegbarkeit, nicht aus dem
         * Formular: Sonst liesse sich mit einem veraenderten Feld ein
         * Termin bei jemandem buchen, der zu dieser Zeit gar nicht
         * arbeitet - und die Kollisionspruefung liefe ins Leere.
         */
        $passt = false;
        foreach (Bookings::freieZeiten($serviceId, substr($start, 0, 10), 0,
                     (int) $service['dauer_min'] >= 60 ? 30 : 15) as $z) {
            if ((string) $z['start'] === $start) {
                $passt     = true;
                $trainerId = (int) $z['trainer_id'];
                break;
            }
        }
        if (!$passt) {
            App::melden('Diese Zeit ist inzwischen vergeben. Bitte eine andere wählen.', 'fehler');
            App::weiter($zurueck);
        }

        /* Ein offenes Paket wird zuerst verbraucht - der Kunde hat es
           bezahlt. Welches es trifft, stand vor dem Buchen auf der
           Bestaetigungsseite; ueberraschen soll es niemanden. */
        $paketId = 0;
        foreach (Commerce::offenePakete((int) $kunde['id']) as $p) {
            $paketId = (int) $p['id'];
            break;
        }

        [$id, $meldung] = Bookings::buchen([
            'service_id'  => $serviceId,
            'customer_id' => (int) $kunde['id'],
            'trainer_id'  => $trainerId,
            'start'       => $start,
            'dauer_min'   => (int) $service['dauer_min'],
            'titel'       => (string) $service['name'],
            'preis_cent'  => (int) $service['preis_cent'],
            'notiz'       => $notiz,
            'customer_package_id' => $paketId,
            'quelle'      => 'portal',
        ]);

        if ($id === 0) {
            App::melden($meldung, 'fehler');
            App::weiter($zurueck);
        }

        $ort = Tenant::find('locations', (int) $service['location_id']);
        Mail::anKunden($kunde, 'Dein Termin am ' . Util::datum($start),
            "Hallo " . (string) $kunde['vorname'] . ",\n\n"
            . "dein Termin steht:\n\n"
            . Util::datumLang($start) . ", " . Util::uhrzeit($start) . " Uhr\n"
            . (string) $service['name'] . "\n"
            . ($ort !== null ? (string) $ort['name'] . "\n" : '')
            . "mit " . Auth::trainerName($trainerId) . "\n\n"
            . ((string) Tenant::einstellung('buchung_bestaetigung', '') !== ''
               ? Tenant::einstellung('buchung_bestaetigung') . "\n\n" : '')
            . "Absagen kannst du bis "
            . (int) Tenant::einstellung('stornofrist_stunden', 24) . " Stunden vorher kostenfrei –\n"
            . "am einfachsten in deinem Bereich.\n\n"
            . Tenant::name());

        App::melden('Termin gebucht: ' . Util::datumLang($start) . ', '
                  . Util::uhrzeit($start) . ' Uhr.'
                  . ($paketId > 0 ? ' Die Einheit wurde von deinem Paket abgezogen.' : ''));
        App::weiter('/portal/?ansicht=termine');
    }

    if ($aktion === 'nachricht') {
        $text = trim(App::post('text'));
        if ($text !== '') {
            Tenant::insert('communications', [
                'customer_id' => (int) $kunde['id'], 'kanal' => 'portal', 'richtung' => 'eingehend',
                'betreff' => 'Nachricht aus dem Portal', 'text' => $text,
                'status' => 'neu', 'gelesen' => 0, 'erstellt' => Util::jetzt(),
            ]);
            Notify::senden('customer', 'Nachricht von ' . Customers::name($kunde),
                Util::kuerzen($text, 90), '/app/kunde.php?id=' . (int) $kunde['id']);
            App::melden('Nachricht verschickt.');
        }
        App::weiter('/portal/?ansicht=nachrichten');
    }

    if ($aktion === 'profil') {
        $daten = [
            'telefon' => App::post('telefon'),
            'strasse' => App::post('strasse'),
            'plz'     => App::post('plz'),
            'ort'     => App::post('ort'),
            'ziele'   => App::post('ziele'),
            'newsletter' => App::postBool('newsletter') ? 1 : 0,
        ];
        // Das Handicap darf der Kunde selbst pflegen – er kennt es besser.
        $hcp = trim(App::post('hcp'));
        if ($hcp !== '') {
            $daten['hcp'] = Util::hcpNormal($hcp);
        }
        Tenant::update('customers', (int) $kunde['id'], $daten);

        /* Einwilligung protokollieren, wenn sie sich geändert hat. */
        if ((int) $kunde['newsletter'] !== (int) $daten['newsletter']) {
            Tenant::insert('consents', [
                'customer_id' => (int) $kunde['id'],
                'typ' => 'newsletter',
                'erteilt' => (int) $daten['newsletter'],
                'text' => 'Newsletter-Einstellung im Kundenportal geändert.',
                'quelle' => 'portal', 'ip' => '', 'erstellt' => Util::jetzt(),
            ]);
        }

        $neu = App::postRoh('passwort');
        if ($neu !== '') {
            /*
             * Wer schon ein Passwort hat, muss es nennen.
             *
             * Sonst genügt eine offene Sitzung, um den Zugang dauerhaft zu
             * übernehmen – ein geliehener Laptop, ein nicht abgemeldetes
             * Familien-Tablet, ein weitergeleiteter Zugangslink. Wer über
             * den Link hereinkam und noch kein Passwort hat, darf eines
             * setzen: Dafür ist der Link da.
             */
            if (Kundenlogin::hatPasswort($kunde)
                && !password_verify(App::postRoh('passwort_alt'), (string) $kunde['portal_passwort'])) {
                App::melden('Das bisherige Passwort stimmt nicht. Die übrigen Daten wurden gespeichert.', 'fehler');
                App::weiter('/portal/?ansicht=profil');
            }
            [$okay, $meldung] = Auth::passwortPruefen($neu);
            if (!$okay) {
                App::melden($meldung, 'fehler');
                App::weiter('/portal/?ansicht=profil');
            }
            Tenant::update('customers', (int) $kunde['id'], ['portal_passwort' => Auth::hash($neu)]);

            /* Ein Passwortwechsel ist der Vorgang, bei dem eine Nachricht am
               meisten wert ist: Wer sie bekommt, ohne ihn ausgelöst zu haben,
               weiß sofort, dass jemand an seinem Konto war. */
            Mail::anKunden($kunde, 'Dein Passwort wurde geändert',
                "Hallo " . (string) $kunde['vorname'] . ",\n\n"
                . "das Passwort für deinen Bereich wurde gerade geändert.\n\n"
                . "Warst du das nicht, melde dich bitte umgehend bei uns – dann\n"
                . "sperren wir den Zugang und richten ihn neu ein.\n\n"
                . Tenant::name());

            App::melden('Daten und Passwort gespeichert.');
        } else {
            App::melden('Daten gespeichert.');
        }
        App::weiter('/portal/?ansicht=profil');
    }

    if ($aktion === 'datenanfrage') {
        $typ = App::post('typ') === 'loeschung' ? 'loeschung' : 'export';
        $offen = Tenant::count('data_requests',
            "customer_id = :k AND typ = :t AND status != 'erledigt'",
            ['k' => (int) $kunde['id'], 't' => $typ]);
        if ($offen > 0) {
            App::melden('Die Anfrage liegt bereits vor und wird bearbeitet.', 'info');
        } else {
            Tenant::insert('data_requests', [
                'customer_id' => (int) $kunde['id'], 'typ' => $typ, 'status' => 'offen',
                'notiz' => 'Über das Kundenportal gestellt.', 'erstellt' => Util::jetzt(),
            ]);
            Notify::senden('system', $typ === 'loeschung' ? 'Löschanfrage' : 'Auskunftsanfrage',
                Customers::name($kunde) . ' hat eine Anfrage gestellt. Frist: ein Monat.',
                '/app/datenschutz.php');
            App::melden('Anfrage aufgenommen. Sie wird innerhalb eines Monats beantwortet.');
        }
        App::weiter('/portal/?ansicht=profil');
    }
}

/* ------------------------------------------------------------- Daten --- */

$kundeId = (int) $kunde['id'];
$akte    = Customers::akte($kundeId);
$zahlen  = Customers::kennzahlen($kundeId);
$naechster = $akte['kommend'][0] ?? null;

/*
 * Die Golfreisen des Kunden – nur über die Kundennummer, nicht über die
 * E-Mail-Adresse: Eine Buchung, die jemand anderes mit dieser Adresse im
 * Backend angelegt hat, ist nicht automatisch seine. Die Reise selbst
 * kommt über Tenant::find() und damit nur aus diesem Betrieb.
 */
$reisenAn = Tenant::modul('travel');
$meineReisen = ['kommend' => [], 'vergangen' => []];
if ($reisenAn) {
    foreach (Tenant::all('trip_signups', 'customer_id = :k', ['k' => $kundeId], 'erstellt DESC') as $b) {
        $r = Tenant::find('trips', (int) $b['trip_id']);
        if ($r === null) {
            continue;
        }
        $vorbei = strtotime((string) $r['ende']) < strtotime(Util::heute());
        $meineReisen[$vorbei || (string) $b['status'] === 'storniert' ? 'vergangen' : 'kommend'][] = ['buchung' => $b, 'reise' => $r];
    }
    usort($meineReisen['kommend'], static fn ($a, $b) => strcmp((string) $a['reise']['start'], (string) $b['reise']['start']));
}
if ($ansicht === 'reisen' && !$reisenAn) {
    $ansicht = 'start';
}

$titel = match ($ansicht) {
    'reisen'      => 'Meine Reisen',
    'buchen'      => 'Termin buchen',
    'termine'     => 'Meine Termine',
    'training'    => 'Mein Training',
    'fortschritt' => 'Mein Fortschritt',
    'unterlagen'  => 'Unterlagen',
    'nachrichten' => 'Nachrichten',
    'profil'      => 'Meine Daten',
    default       => 'Hallo ' . (string) $kunde['vorname'],
};
require __DIR__ . '/partials/kopf.php';

/* ============================================================ Start === */
if ($ansicht === 'start'):
?>
  <?php if ($naechster !== null): ?>
    <div class="naechster">
      <div class="naechster__label">Dein nächster Termin</div>
      <div class="naechster__tag"><?= Util::h(Util::datumLang((string) $naechster['start'])) ?></div>
      <div class="naechster__zeit">
        <?= Util::h(Util::uhrzeit((string) $naechster['start'])) ?>–<?= Util::h(Util::uhrzeit((string) $naechster['ende'])) ?> Uhr
        · <?= Util::h(Util::relativ((string) $naechster['start'])) ?>
      </div>
      <div class="naechster__was">
        <strong><?= Util::h((string) $naechster['titel']) ?></strong>
        <?php $ort = Tenant::find('locations', (int) $naechster['location_id']); ?>
        <?php if ($ort !== null): ?>
          <div class="naechster__reihe"><?= Icon::svg('pin', 14) ?>
            <span><?= Util::h((string) $ort['name']) ?><?= (string) $ort['notiz'] !== ''
                  ? ' · ' . Util::h((string) $ort['notiz']) : '' ?></span></div>
        <?php endif; ?>
        <div class="naechster__reihe"><?= Icon::svg('user', 14) ?>
          <span>mit <?= Util::h(Auth::trainerName((int) $naechster['trainer_id'])) ?></span></div>
      </div>
      <div class="naechster__knoepfe">
        <a class="btn btn--klein" href="<?= Util::attr(App::url('/portal/?ansicht=termine')) ?>">
          Alle Termine</a>
        <a class="btn btn--klein" href="<?= Util::attr(App::url('/portal/?ansicht=buchen')) ?>">
          Weiteren Termin buchen</a>
      </div>
    </div>
  <?php else: ?>
    <div class="karte mb-5"><div class="karte__koerper mitte">
      <div class="leerzustand__symbol" style="margin:0 auto 12px"><?= Icon::svg('calendar', 24) ?></div>
      <h3 class="mb-2">Kein Termin geplant</h3>
      <p class="gedimmt klein mb-4">Such dir eine freie Zeit aus – oder schreib deinem
        Trainer kurz, wann es dir passt.</p>
      <div class="reihe reihe--umbruch" style="justify-content:center">
        <a class="btn btn--primaer" href="<?= Util::attr(App::url('/portal/?ansicht=buchen')) ?>">
          <?= Icon::svg('calendar', 15) ?> Termin buchen</a>
        <a class="btn btn--rand" href="<?= Util::attr(App::url('/portal/?ansicht=nachrichten')) ?>">
          <?= Icon::svg('message', 15) ?> Nachricht schreiben</a>
      </div>
    </div></div>
  <?php endif; ?>

  <?php if ($meineReisen['kommend'] !== []):
    $nr = $meineReisen['kommend'][0];
    $nrBild = Reiseseite::bildUrl((string) $nr['reise']['bild']);
    $nrTage = Util::tageBis((string) $nr['reise']['start']); ?>
    <a class="pt-reisehinweis mb-5" href="<?= Util::attr(App::url('/portal/?ansicht=reisen')) ?>">
      <?php if ($nrBild !== ''): ?><span class="pt-reisehinweis__bild"><img src="<?= Util::attr($nrBild) ?>" alt=""></span><?php endif; ?>
      <span class="pt-reisehinweis__text">
        <span class="pt-reisehinweis__label"><?= count($meineReisen['kommend']) === 1 ? 'Deine Reise' : 'Deine nächste Reise' ?></span>
        <span class="pt-reisehinweis__titel"><?= Util::h((string) $nr['reise']['titel']) ?></span>
        <span class="pt-reisehinweis__unter"><?= Util::h(Reiseseite::zeitraum((string) $nr['reise']['start'], (string) $nr['reise']['ende'])) ?>
          <?= $nrTage > 0 ? ' · in ' . $nrTage . ($nrTage === 1 ? ' Tag' : ' Tagen') : '' ?></span>
      </span>
      <?= Icon::svg('chevron-right', 18) ?>
    </a>
  <?php endif; ?>

  <div class="pt-kacheln mb-5">
    <a class="pt-kachel" href="<?= Util::attr(App::url('/portal/?ansicht=termine')) ?>">
      <span class="pt-kachel__symbol"><?= Icon::svg('calendar', 17) ?></span>
      <span class="pt-kachel__wert"><?= (int) $zahlen['termine'] ?></span>
      <span class="pt-kachel__label">Einheiten bisher</span></a>
    <a class="pt-kachel" href="<?= Util::attr(App::url('/portal/?ansicht=unterlagen')) ?>">
      <span class="pt-kachel__symbol"><?= Icon::svg('ticket', 17) ?></span>
      <span class="pt-kachel__wert"><?= (int) $zahlen['einheiten'] ?></span>
      <span class="pt-kachel__label">Offene Einheiten</span></a>
    <a class="pt-kachel" href="<?= Util::attr(App::url('/portal/?ansicht=fortschritt')) ?>">
      <span class="pt-kachel__symbol"><?= Icon::svg('trend-up', 17) ?></span>
      <span class="pt-kachel__wert"><?= Util::h(Util::hcp((string) $kunde['hcp'])) ?></span>
      <span class="pt-kachel__label">Handicap</span></a>
    <a class="pt-kachel" href="<?= Util::attr(App::url('/portal/?ansicht=training')) ?>">
      <span class="pt-kachel__symbol"><?= Icon::svg('training', 17) ?></span>
      <span class="pt-kachel__wert"><?= count($akte['plaene']) ?></span>
      <span class="pt-kachel__label">Trainingspläne</span></a>
  </div>

  <?php if ((string) $kunde['ziele'] !== ''): ?>
    <div class="karte mb-5"><div class="karte__koerper">
      <div class="reihe reihe--eng mb-2"><?= Icon::svg('target', 16) ?>
        <span class="halbfett">Dein Ziel</span></div>
      <p><?= Util::h((string) $kunde['ziele']) ?></p>
    </div></div>
  <?php endif; ?>

  <h2 class="mb-3" style="font-size:15px">Zuletzt</h2>
  <div class="pt-liste">
    <?php
    $vergangen = array_slice(array_filter($akte['buchungen'],
        static fn ($b) => strtotime((string) $b['start']) < time()), 0, 4);
    foreach ($vergangen as $b): ?>
      <div class="pt-zeile">
        <span class="pt-zeile__datum">
          <span class="pt-zeile__tag"><?= (int) date('j', strtotime((string) $b['start'])) ?></span>
          <span class="pt-zeile__monat"><?= Util::h(mb_substr(Util::monatName((int) date('n', strtotime((string) $b['start']))), 0, 3)) ?></span>
        </span>
        <span class="pt-zeile__text">
          <span class="pt-zeile__titel"><?= Util::h((string) $b['titel']) ?></span>
          <span class="pt-zeile__unter"><?= Util::h(Util::uhrzeit((string) $b['start'])) ?> Uhr
            · <?= Util::h(Auth::trainerName((int) $b['trainer_id'])) ?></span>
        </span>
      </div>
    <?php endforeach; ?>
    <?php if ($vergangen === []): ?>
      <div class="pt-zeile"><span class="gedimmt klein">Noch nichts passiert – das ändert sich bald.</span></div>
    <?php endif; ?>
  </div>

<?php
/* =========================================================== Buchen === */
/*
 * Termin buchen, ohne den Kundenbereich zu verlassen.
 *
 * Zwei Schritte statt drei: Zeit waehlen, bestaetigen. Der dritte Schritt
 * der oeffentlichen Buchung - Name, E-Mail, Einwilligung - faellt weg,
 * weil hier niemand fremd ist. Genau das ist der Gewinn gegenueber dem
 * Weg ueber die Website.
 *
 * Die Auswahl laeuft ueber Links, nicht ueber Formulare: Eine gewaehlte
 * Zeit ist keine Aenderung, sie ist ein Ort. So laesst sie sich
 * verschicken, der Zurueck-Knopf tut das Erwartete, und ein versehentlich
 * neu geladener Schritt bucht nichts doppelt. Gebucht wird erst per POST,
 * mit Merkmal gegen gefaelschte Anfragen.
 */
elseif ($ansicht === 'buchen'):
  $leistungen = Tenant::all('services', 'aktiv = 1 AND online_buchbar = 1', [], 'position, name');

  if ($leistungen === []):
?>
  <div class="karte"><div class="karte__koerper mitte">
    <div class="leerzustand__symbol" style="margin:0 auto 12px"><?= Icon::svg('calendar', 24) ?></div>
    <h3 class="mb-2">Online-Buchung ist nicht freigeschaltet</h3>
    <p class="gedimmt klein mb-4">Schreib deinem Trainer kurz, wann es dir passt –
      er tr&auml;gt den Termin dann f&uuml;r dich ein.</p>
    <a class="btn btn--primaer" href="<?= Util::attr(App::url('/portal/?ansicht=nachrichten')) ?>">
      <?= Icon::svg('message', 15) ?> Nachricht schreiben</a>
  </div></div>
<?php
  else:
    /* Gewaehlte Leistung – oder die erste, solange keine dasteht. */
    $serviceId = App::getInt('service_id');
    $service   = $serviceId > 0 ? Tenant::find('services', $serviceId) : null;
    if ($service === null || (int) $service['online_buchbar'] !== 1 || (int) $service['aktiv'] !== 1) {
        $service   = $leistungen[0];
        $serviceId = (int) $service['id'];
    }

    /*
     * Das Raster der angebotenen Startzeiten – dieselbe Ueberlegung wie
     * auf der Website: Viertelstunden bei kurzen Einheiten, halbe bei
     * langen. Ein leerer Tag im Viertelstundentakt waere eine Wand aus
     * vierzig Knoepfen, und niemand faengt gern um 12:45 an.
     */
    $raster = (int) $service['dauer_min'] >= 60 ? 30 : 15;

    $abTag = App::get('datum', Util::heute());
    if (strtotime($abTag) === false || $abTag < Util::heute()) {
        $abTag = Util::heute();
    }

    /* Die naechsten Tage mit freien Zeiten – hoechstens fuenf, damit die
       Seite nicht endlos wird und die Abfrage nicht ausufert. */
    $tage = [];
    for ($i = 0; $i < 21 && count($tage) < 5; $i++) {
        $tag  = date('Y-m-d', strtotime($abTag . ' +' . $i . ' days'));
        $frei = Bookings::freieZeiten($serviceId, $tag, 0, $raster);
        if ($frei !== []) {
            $tage[$tag] = $frei;
        }
    }

    /*
     * Eine gewaehlte Zeit gilt nur, wenn sie in diesem Moment noch frei
     * ist. Ein alter Link oder ein lange offener Tab zeigt sonst eine
     * Bestaetigungsseite fuer einen Platz, den es nicht mehr gibt – und
     * die Absage kaeme erst nach dem Klick auf „verbindlich buchen".
     */
    $gewaehlt  = App::get('start');
    $trainerId = 0;
    if ($gewaehlt !== '') {
        $trainerId = 0;
        foreach (Bookings::freieZeiten($serviceId, substr($gewaehlt, 0, 10), 0, $raster) as $z) {
            if ((string) $z['start'] === $gewaehlt) {
                $trainerId = (int) $z['trainer_id'];
                break;
            }
        }
        if ($trainerId === 0) {
            $gewaehlt = '';
            echo '<div class="hinweis hinweis--gefahr mb-4">' . Icon::svg('alert', 17)
               . '<div class="hinweis__text">Diese Zeit ist inzwischen vergeben. '
               . 'Unten stehen die aktuell freien.</div></div>';
        }
    }

    $paket = Commerce::offenePakete((int) $kunde['id'])[0] ?? null;
    $ort   = Tenant::find('locations', (int) $service['location_id']);
    $frist = (int) Tenant::einstellung('stornofrist_stunden', 24);

    $adresse = static fn (array $werte): string =>
        Util::attr(App::url('/portal/?' . http_build_query(array_filter(
            ['ansicht' => 'buchen'] + $werte,
            static fn ($v) => $v !== '' && $v !== 0))));

    /* ---------------------------------------- Schritt 2: bestaetigen -- */
    if ($gewaehlt !== ''):
?>
  <div class="buchen-bestaetigung">
    <div class="buchen-bestaetigung__tag"><?= Util::h(Util::datumLang($gewaehlt)) ?></div>
    <div class="buchen-bestaetigung__zeit">
      <?= Util::h(Util::uhrzeit($gewaehlt)) ?> Uhr · <?= (int) $service['dauer_min'] ?> Minuten
    </div>
    <div class="buchen-bestaetigung__was">
      <strong><?= Util::h((string) $service['name']) ?></strong>
      <?php if ($ort !== null): ?>
        <div class="buchen-bestaetigung__reihe"><?= Icon::svg('pin', 14) ?>
          <span><?= Util::h((string) $ort['name']) ?></span></div>
      <?php endif; ?>
      <div class="buchen-bestaetigung__reihe"><?= Icon::svg('user', 14) ?>
        <span>mit <?= Util::h(Auth::trainerName($trainerId)) ?></span></div>
    </div>
  </div>

  <form method="post" class="karte mb-4">
    <?= Auth::csrfFeld() ?>
    <input type="hidden" name="aktion" value="buchen">
    <input type="hidden" name="service_id" value="<?= $serviceId ?>">
    <input type="hidden" name="start" value="<?= Util::attr($gewaehlt) ?>">
    <div class="karte__koerper">
      <div class="feld">
        <label class="feld__label" for="bnotiz">M&ouml;chtest du noch etwas dazusagen?</label>
        <textarea class="eingabe" id="bnotiz" name="notiz" rows="2"
                  placeholder="Woran du arbeiten m&ouml;chtest, Verletzungen, Sonstiges"></textarea>
      </div>
      <?php
      /*
       * Was das kostet, steht vor dem Klick da – und wenn ein Paket
       * greift, steht auch das da. Eine Einheit, die erst hinterher vom
       * Guthaben abgezogen erscheint, fuehlt sich wie ein Fehler an.
       */
      ?>
      <div class="reihe reihe--zwischen mb-4">
        <span class="gedimmt klein">Preis</span>
        <span class="halbfett">
          <?php if ($paket !== null): ?>
            aus dem Paket
          <?php else: ?>
            <?= Util::h(Util::geld((int) $service['preis_cent'])) ?>
          <?php endif; ?>
        </span>
      </div>
      <?php if ($paket !== null): ?>
        <div class="hinweis hinweis--still mb-4"><?= Icon::svg('ticket', 17) ?>
          <div class="hinweis__text">Diese Einheit wird von deinem Paket
            &bdquo;<?= Util::h((string) $paket['name']) ?>&ldquo; abgezogen &ndash;
            danach sind noch
            <?= max(0, (int) $paket['einheiten_gesamt'] - (int) $paket['einheiten_genutzt'] - 1) ?>
            von <?= (int) $paket['einheiten_gesamt'] ?> &uuml;brig.</div></div>
      <?php endif; ?>
      <button class="btn btn--primaer btn--voll" type="submit">Verbindlich buchen</button>
      <p class="winzig mitte mt-3">Absage bis <?= $frist ?> Stunden vorher kostenfrei.</p>
    </div>
  </form>

  <p class="zugang__wechsel">
    <a href="<?= $adresse(['service_id' => $serviceId, 'datum' => substr($gewaehlt, 0, 10)]) ?>">Andere Zeit w&auml;hlen</a>
  </p>

<?php
    /* ---------------------------------------- Schritt 1: Zeit waehlen - */
    else:
?>
  <form method="get" class="karte mb-4">
    <input type="hidden" name="ansicht" value="buchen">
    <div class="karte__koerper">
      <?php if (count($leistungen) > 1): ?>
        <div class="feld">
          <label class="feld__label" for="bleistung">Was m&ouml;chtest du buchen?</label>
          <select class="eingabe" id="bleistung" name="service_id" onchange="this.form.submit()">
            <?php foreach ($leistungen as $l): ?>
              <option value="<?= (int) $l['id'] ?>"<?= (int) $l['id'] === $serviceId ? ' selected' : '' ?>>
                <?= Util::h((string) $l['name']) ?> ·
                <?= Util::h(Util::geldKurz((int) $l['preis_cent'])) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      <?php endif; ?>
      <div class="feld" style="margin-bottom:0">
        <label class="feld__label" for="bdatum">Ab wann</label>
        <input class="eingabe" id="bdatum" type="date" name="datum"
               value="<?= Util::attr($abTag) ?>" min="<?= Util::attr(Util::heute()) ?>"
               onchange="this.form.submit()">
      </div>
      <noscript><button class="btn btn--klein mt-4" type="submit">Zeiten anzeigen</button></noscript>
    </div>
  </form>

  <?php if ((string) $service['beschreibung'] !== ''): ?>
    <p class="klein gedimmt mb-4"><?= Util::h((string) $service['beschreibung']) ?></p>
  <?php endif; ?>

  <?php if ($paket !== null): ?>
    <div class="hinweis hinweis--still mb-4"><?= Icon::svg('ticket', 17) ?>
      <div class="hinweis__text">Du hast noch
        <?= max(0, (int) $paket['einheiten_gesamt'] - (int) $paket['einheiten_genutzt']) ?>
        Einheiten aus &bdquo;<?= Util::h((string) $paket['name']) ?>&ldquo;.
        Die n&auml;chste Buchung wird davon abgezogen.</div></div>
  <?php endif; ?>

  <?php if ($tage === []): ?>
    <div class="karte"><div class="karte__koerper mitte">
      <div class="leerzustand__symbol" style="margin:0 auto 12px"><?= Icon::svg('calendar', 24) ?></div>
      <h3 class="mb-2">In den n&auml;chsten drei Wochen ist nichts frei</h3>
      <p class="gedimmt klein mb-4">Schreib deinem Trainer kurz &ndash; oft l&auml;sst sich
        doch etwas einrichten.</p>
      <a class="btn btn--primaer" href="<?= Util::attr(App::url('/portal/?ansicht=nachrichten')) ?>">
        <?= Icon::svg('message', 15) ?> Nachricht schreiben</a>
    </div></div>
  <?php else: ?>
    <?php
    /*
     * Die freien Zeiten als Liste, nicht als Knopfwolke: Dreissig gleich
     * aussehende Uhrzeiten nebeneinander zwingen zum Suchen. Das Datum
     * steht nur in der ersten Zeile eines Tages – viermal „Donnerstag,
     * 17. September" untereinander liest niemand, und es verdeckt genau
     * das, was sich von Zeile zu Zeile aendert.
     */
    ?>
    <div class="zeitwahl">
      <?php foreach ($tage as $tag => $frei): ?>
        <?php $erste = true; foreach ($frei as $z): ?>
          <a class="zeitwahl__zeile" href="<?= $adresse([
               'service_id' => $serviceId,
               'datum'      => $abTag,
               'start'      => (string) $z['start'],
             ]) ?>">
            <span class="zeitwahl__tag"><?= $erste ? Util::h(Util::datumLang($tag)) : '' ?></span>
            <span class="zeitwahl__zeit"><?= Util::h(Util::uhrzeit((string) $z['start'])) ?></span>
            <?php /* Die Leistung steht schon in der Auswahl darueber. In jeder
                     Zeile wiederholt, verdeckte sie genau das, was sich von
                     Zeile zu Zeile aendert - deshalb hier das Ende. */ ?>
            <span class="zeitwahl__dauer">bis <?= Util::h(Util::uhrzeit((string) $z['ende'])) ?></span>
          </a>
        <?php $erste = false; endforeach; ?>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
<?php
    endif;
  endif;

/* ========================================================== Termine === */
elseif ($ansicht === 'termine'):
  $frist = (int) Tenant::einstellung('stornofrist_stunden', 24);
?>
  <div class="reihe reihe--zwischen mb-3">
    <h2 style="font-size:15px">Kommende Termine</h2>
    <a class="btn btn--klein" href="<?= Util::attr(App::url('/portal/?ansicht=buchen')) ?>">
      <?= Icon::svg('plus', 14) ?> Termin buchen</a>
  </div>
  <?php if ($akte['kommend'] === []): ?>
    <div class="karte mb-5"><div class="karte__koerper gedimmt klein">
      Zurzeit ist nichts geplant.</div></div>
  <?php else: ?>
    <div class="stapel stapel--eng mb-5">
      <?php foreach ($akte['kommend'] as $b):
        $absagbar = strtotime((string) $b['start']) - time() >= $frist * 3600;
        $ort = Tenant::find('locations', (int) $b['location_id']); ?>
        <div class="karte"><div class="karte__koerper">
          <div class="reihe reihe--zwischen mb-2">
            <span class="halbfett"><?= Util::h((string) $b['titel']) ?></span>
            <?= pille(Util::relativ((string) $b['start']), 'marke') ?>
          </div>
          <div class="klein gedimmt">
            <?= Util::h(Util::datumLang((string) $b['start'])) ?>,
            <?= Util::h(Util::uhrzeit((string) $b['start'])) ?>–<?= Util::h(Util::uhrzeit((string) $b['ende'])) ?> Uhr
            <?php if ($ort !== null): ?> · <?= Util::h((string) $ort['name']) ?><?php endif; ?>
            · <?= Util::h(Auth::trainerName((int) $b['trainer_id'])) ?>
          </div>
          <?php if ((string) $b['notiz'] !== ''): ?>
            <p class="klein mt-3"><?= Util::h((string) $b['notiz']) ?></p>
          <?php endif; ?>
        </div>
        <div class="karte__fuss">
          <span class="winzig gedimmt">
            <?= $absagbar
                ? 'Absage bis ' . $frist . ' Stunden vorher kostenfrei.'
                : 'Die Absagefrist ist vorbei.' ?></span>
          <div class="fueller"></div>
          <?php if ($absagbar): ?>
            <form method="post" class="inline"
                  data-bestaetigen="Termin am <?= Util::attr(Util::datum((string) $b['start'])) ?> wirklich absagen?">
              <?= Auth::csrfFeld() ?><input type="hidden" name="aktion" value="absagen">
              <input type="hidden" name="id" value="<?= (int) $b['id'] ?>">
              <button class="btn btn--klein" type="submit">Absagen</button>
            </form>
          <?php endif; ?>
        </div></div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <h2 class="mb-3" style="font-size:15px">Bisher</h2>
  <div class="pt-liste">
    <?php foreach (array_filter($akte['buchungen'],
            static fn ($b) => strtotime((string) $b['start']) < time()) as $b): ?>
      <div class="pt-zeile">
        <span class="pt-zeile__datum">
          <span class="pt-zeile__tag"><?= (int) date('j', strtotime((string) $b['start'])) ?></span>
          <span class="pt-zeile__monat"><?= Util::h(mb_substr(Util::monatName((int) date('n', strtotime((string) $b['start']))), 0, 3)) ?></span>
        </span>
        <span class="pt-zeile__text">
          <span class="pt-zeile__titel"><?= Util::h((string) $b['titel']) ?></span>
          <span class="pt-zeile__unter"><?= Util::h(Util::datum((string) $b['start'])) ?>
            · <?= Util::h(Auth::trainerName((int) $b['trainer_id'])) ?></span>
        </span>
        <?php if ((string) $b['status'] === 'abgesagt'): ?><?= pille('abgesagt') ?><?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>

<?php
/* ========================================================= Training === */
elseif ($ansicht === 'training'):
?>
  <?php if ($akte['plaene'] === [] && $akte['videos'] === [] && $akte['kurse'] === []): ?>
    <div class="karte"><div class="karte__koerper mitte">
      <div class="leerzustand__symbol" style="margin:0 auto 12px"><?= Icon::svg('training', 24) ?></div>
      <h3 class="mb-2">Noch kein Trainingsplan</h3>
      <p class="gedimmt klein">Sobald dein Trainer dir einen Plan zuweist, steht er hier –
        mit allen Übungen und dem, was du zwischen den Stunden üben sollst.</p>
    </div></div>
  <?php endif; ?>

  <?php foreach ($akte['plaene'] as $plan):
    $items = Tenant::all('plan_items', 'plan_id = :p', ['p' => (int) $plan['id']], 'woche, einheit, position'); ?>
    <div class="karte mb-4">
      <div class="karte__kopf">
        <h2><?= Util::h((string) $plan['name']) ?></h2>
        <div class="karte__kopf-aktionen"><?= pille((int) $plan['fortschritt'] . ' %', 'marke') ?></div>
      </div>
      <div class="karte__koerper">
        <?php if ((string) $plan['ziel'] !== ''): ?>
          <p class="klein gedimmt mb-3"><?= Util::h((string) $plan['ziel']) ?></p>
        <?php endif; ?>
        <?= balken((int) $plan['fortschritt']) ?>
        <div class="stapel stapel--eng mt-4">
          <?php
          $wocheAlt = -1;
          foreach ($items as $item):
            if ((int) $item['woche'] !== $wocheAlt):
              $wocheAlt = (int) $item['woche']; ?>
              <div class="versal gedimmt mt-3">Woche <?= $wocheAlt ?></div>
            <?php endif; ?>
            <div class="reihe reihe--eng">
              <span style="color:<?= (int) $item['erledigt'] === 1 ? 'var(--erfolg)' : 'var(--text-4)' ?>;display:flex">
                <?= Icon::svg((int) $item['erledigt'] === 1 ? 'check' : 'clock', 14) ?></span>
              <span style="flex:1"><?= Util::h((string) $item['titel']) ?></span>
              <?php if ((int) $item['dauer_min'] > 0): ?>
                <span class="winzig gedimmt"><?= (int) $item['dauer_min'] ?> Min</span>
              <?php endif; ?>
            </div>
            <?php if ((string) $item['notiz'] !== ''): ?>
              <div class="winzig gedimmt" style="padding-left:22px"><?= Util::h((string) $item['notiz']) ?></div>
            <?php endif; ?>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
  <?php endforeach; ?>

  <?php
  $freigegeben = array_filter($akte['analysen'], static fn ($a) => (int) $a['freigegeben'] === 1);
  if ($freigegeben !== []): ?>
    <h2 class="mb-3 mt-5" style="font-size:15px">Videoanalysen</h2>
    <?php foreach ($freigegeben as $a):
      $video = Tenant::find('videos', (int) $a['video_id']); ?>
      <div class="karte mb-3"><div class="karte__koerper">
        <div class="reihe reihe--zwischen mb-2">
          <span class="halbfett"><?= Util::h((string) $a['titel']) ?></span>
          <span class="winzig gedimmt"><?= Util::h(Util::datum((string) $a['erstellt'])) ?></span>
        </div>
        <?php if ($video !== null && (string) $video['datei'] !== ''): ?>
          <video class="mb-3" controls playsinline preload="metadata"
                 style="width:100%;border-radius:var(--radius-m);background:#000"
                 src="<?= Util::attr(App::url('/datei.php?art=video&id=' . (int) $video['id'])) ?>"></video>
        <?php endif; ?>
        <?php if ((string) $a['pro_analyse'] !== ''): ?>
          <div class="halbfett klein mb-2">Einschätzung deines Trainers</div>
          <p class="klein"><?= nl2br(Util::h((string) $a['pro_analyse'])) ?></p>
        <?php endif; ?>
        <?php if ((string) $a['empfehlungen'] !== ''): ?>
          <div class="hinweis hinweis--still mt-3">
            <?= Icon::svg('target', 16) ?>
            <div class="hinweis__text klein"><?= nl2br(Util::h((string) $a['empfehlungen'])) ?></div>
          </div>
        <?php endif; ?>
      </div></div>
    <?php endforeach; ?>
  <?php endif; ?>

  <?php if ($akte['kurse'] !== []): ?>
    <h2 class="mb-3 mt-5" style="font-size:15px">Meine Kurse</h2>
    <div class="pt-liste">
      <?php foreach ($akte['kurse'] as $k): ?>
        <div class="pt-zeile">
          <span class="pt-zeile__text">
            <span class="pt-zeile__titel"><?= Util::h((string) $k['titel']) ?></span>
            <span class="pt-zeile__unter"><?= (int) $k['fortschritt'] ?> % abgeschlossen</span>
          </span>
          <?php if ((int) $k['fortschritt'] >= 100): ?><?= pille('fertig', 'erfolg') ?><?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

<?php
/* ====================================================== Fortschritt === */
elseif ($ansicht === 'fortschritt'):
  $leistung = array_reverse($akte['leistung']);
  $hcpWerte = array_values(array_filter(array_map(
      static fn ($e) => Util::zahlAus((string) $e['hcp']), $leistung), static fn ($v) => $v > 0));
?>
  <?php
  /*
   * Das Handicap ist die Zahl, wegen der diese Seite geöffnet wird – also
   * steht sie groß da und nicht als eine Kachel unter vier gleichen. Der
   * Satz darunter sagt in Worten, was die Ziffer bedeutet: „von 28,0 im
   * April" ist verständlicher als jedes Pfeilsymbol.
   */
  $erster = $hcpWerte[0] ?? null;
  $letzter = $hcpWerte !== [] ? end($hcpWerte) : null;
  $seitDatum = $leistung[0]['datum'] ?? '';
  ?>
  <div class="zwei-spalten" style="display:flex;flex-wrap:wrap;gap:clamp(30px,5vw,70px);align-items:flex-end">
    <div class="grosse-zahl" style="flex:1 1 240px;min-width:0;margin-bottom:0">
      <div class="grosse-zahl__label">Handicap aktuell</div>
      <div class="grosse-zahl__wert"><?= Util::h(Util::hcp((string) $kunde['hcp'])) ?></div>
      <?php if ($erster !== null && $letzter !== null && abs($erster - $letzter) >= 0.1): ?>
        <div class="grosse-zahl__notiz">
          von <?= Util::h(str_replace('.', ',', number_format($erster, 1, '.', ''))) ?>
          <?= $seitDatum !== '' ? 'im ' . Util::h(Util::monatName((int) date('n', strtotime((string) $seitDatum)))) : '' ?>
          — <?= Util::h(str_replace('.', ',', number_format(abs($erster - $letzter), 1, '.', ''))) ?>
          <?= $letzter < $erster ? 'weniger' : 'mehr' ?>
        </div>
      <?php endif; ?>
    </div>

    <?php if (count($hcpWerte) > 1): ?>
      <?php
      /*
       * Balken statt Linie: Auf dem Telefon ist eine Kurve über acht
       * Messpunkte nicht zu lesen. Die Höhe zeigt die Spanne zwischen
       * bestem und schlechtestem Wert, umgedreht – niedriges Handicap,
       * hoher Balken.
       */
      $min = min($hcpWerte); $max = max($hcpWerte);
      $spanne = max(0.1, $max - $min);
      ?>
      <div style="flex:1 1 300px;min-width:0">
        <div class="kurve-balken">
          <?php foreach ($hcpWerte as $i => $w):
            $anteil = 28 + (1 - ($w - $min) / $spanne) * 72; ?>
            <div style="height:<?= round($anteil) ?>%;background:<?= $i === count($hcpWerte) - 1
                ? 'var(--marke)' : 'var(--flaeche)' ?>"
                 title="<?= Util::attr(Util::hcp((string) $w)) ?>"></div>
          <?php endforeach; ?>
        </div>
        <div class="kurve-achse">
          <span><?= Util::h(Util::datum((string) ($leistung[0]['datum'] ?? ''), false)) ?></span>
          <span>heute</span>
        </div>
      </div>
    <?php endif; ?>
  </div>

  <div class="pt-kacheln" style="margin-top:clamp(34px,5vw,54px)">
    <div class="pt-kachel">
      <span class="pt-kachel__wert"><?= count($akte['leistung']) ?></span>
      <span class="pt-kachel__label">Erfasste Runden</span></div>
    <div class="pt-kachel">
      <span class="pt-kachel__wert"><?= (int) $zahlen['termine'] ?></span>
      <span class="pt-kachel__label">Einheiten</span></div>
    <div class="pt-kachel">
      <span class="pt-kachel__wert"><?= (int) $zahlen['einheiten'] ?></span>
      <span class="pt-kachel__label">Offen im Paket</span></div>
    <div class="pt-kachel">
      <span class="pt-kachel__wert"><?= Util::h(Util::datum((string) $zahlen['seit'], false)) ?></span>
      <span class="pt-kachel__label">Dabei seit</span></div>
  </div>

  <?php if ($akte['leistung'] !== []): ?>
    <p class="abschnitt-titel">Deine Runden</p>
    <div>
      <div class="tabelle-huelle">
        <table class="tabelle tabelle--eng">
          <thead><tr><th>Datum</th><th>HCP</th><th>Score</th>
            <th class="nicht-mobil">Fairways</th><th class="nicht-mobil">GIR</th><th>Putts</th></tr></thead>
          <tbody>
          <?php foreach (array_slice($akte['leistung'], 0, 20) as $e): ?>
            <tr>
              <td class="klein gedimmt"><?= Util::h(Util::datum((string) $e['datum'])) ?></td>
              <td class="tabnum"><?= Util::h(Util::hcp((string) $e['hcp'])) ?></td>
              <td class="tabnum"><?= (int) $e['score'] > 0 ? (int) $e['score'] : '–' ?></td>
              <td class="nicht-mobil tabnum"><?= (int) $e['fairways'] > 0 ? (int) $e['fairways'] : '–' ?></td>
              <td class="nicht-mobil tabnum"><?= (int) $e['gir'] > 0 ? (int) $e['gir'] : '–' ?></td>
              <td class="tabnum"><?= (int) $e['putts'] > 0 ? (int) $e['putts'] : '–' ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  <?php else: ?>
    <div class="karte"><div class="karte__koerper mitte">
      <div class="leerzustand__symbol" style="margin:0 auto 12px"><?= Icon::svg('activity', 24) ?></div>
      <h3 class="mb-2">Noch keine Runden erfasst</h3>
      <p class="gedimmt klein">Sobald Runden oder Messwerte eingetragen sind, siehst du hier,
        wie sich dein Spiel entwickelt.</p>
    </div></div>
  <?php endif; ?>

<?php
/* ============================================================ Reisen === */
elseif ($ansicht === 'reisen'):
  /*
   * Was der Kunde zu seiner Reise wissen will: Ist sie bestätigt, wer
   * fährt mit, was ist gebucht, was muss ich wann zahlen und wohin. Die
   * Beträge stehen so, wie sie beim Buchen berechnet und gespeichert
   * wurden – eine spätere Preisänderung an der Reise ändert keine
   * bestehende Buchung.
   */
  $w = Tenant::waehrung();
  $bank = trim((string) Tenant::einstellung('bank', ''));
  $statusText = [
      'angemeldet' => ['Eingegangen – wartet auf Bestätigung', 'offen'],
      'bestaetigt' => ['Bestätigt', 'erfolg'],
      'warteliste' => ['Warteliste', 'offen'],
      'storniert'  => ['Storniert', ''],
  ];
?>
  <?php if ($meineReisen['kommend'] === [] && $meineReisen['vergangen'] === []): ?>
    <div class="karte"><div class="karte__koerper gedimmt klein">
      Hier erscheinen deine Golfreisen, sobald du eine gebucht hast.
      <a class="pt-link" href="<?= Util::attr(Oeffentlich::url('/reisen.php')) ?>">Zu den Reisen</a>
    </div></div>
  <?php endif; ?>

  <?php foreach ($meineReisen['kommend'] as ['buchung' => $b, 'reise' => $r]):
    $bild     = Reiseseite::bildUrl((string) $r['bild']);
    $tage     = Util::tageBis((string) $r['start']);
    $summe    = (int) $b['preis_cent'];
    $anz      = (int) $b['anzahlung_cent'];
    $bestaetigt = (string) $b['status'] === 'bestaetigt';
    $offen    = $bestaetigt && (((int) $b['anzahlung_bezahlt'] !== 1 && $anz > 0) || (int) $b['bezahlt'] !== 1);
    [$stText, $stArt] = $statusText[(string) $b['status']] ?? [(string) $b['status'], ''];
    $extras   = (array) Util::ausJson((string) ($b['extras'] ?? ''), []); ?>
    <article class="pt-reise">
      <?php if ($bild !== ''): ?><div class="pt-reise__bild"><img src="<?= Util::attr($bild) ?>" alt=""></div><?php endif; ?>
      <div class="pt-reise__koerper">
        <div class="pt-reise__kopf">
          <div>
            <h2 class="pt-reise__titel"><?= Util::h((string) $r['titel']) ?></h2>
            <p class="pt-reise__zeit"><?= Util::h(trim((string) $r['ziel'] . ', ' . (string) $r['land'], ', ')) ?>
              · <?= Util::h(Reiseseite::zeitraum((string) $r['start'], (string) $r['ende'])) ?>
              · <?= (int) $r['naechte'] ?> Nächte</p>
          </div>
          <?= pille($stText, $stArt) ?>
        </div>
        <?php if ($tage > 0 && (string) $b['status'] !== 'warteliste'): ?>
          <p class="pt-reise__countdown">Noch <?= $tage ?> <?= $tage === 1 ? 'Tag' : 'Tage' ?></p>
        <?php endif; ?>

        <div class="pt-reise__teile">
          <div>
            <div class="pt-reise__label">Reisende</div>
            <ul class="pt-reise__liste">
              <?php foreach (Trips::reisende($b) as $p): ?>
                <li><?= Util::h($p['name']) ?><span class="pt-reise__klein"><?=
                  $p['golfer'] ? ($p['hcp'] !== '' ? ' · HCP ' . Util::h($p['hcp']) : '') : ' · Nichtgolfer' ?></span></li>
              <?php endforeach; ?>
            </ul>
            <div class="pt-reise__label mt-4">Gebucht</div>
            <ul class="pt-reise__liste">
              <li><?= Util::h(Trips::ZIMMER[(string) $b['zimmer']] ?? (string) $b['zimmer']) ?></li>
              <?php if ((string) $r['hotel'] !== ''): ?><li><?= Util::h((string) $r['hotel']) ?></li><?php endif; ?>
              <?php foreach ($extras as $x): ?>
                <li><?= (int) ($x['anzahl'] ?? 1) ?> × <?= Util::h((string) ($x['name'] ?? '')) ?></li>
              <?php endforeach; ?>
            </ul>
          </div>
          <div>
            <div class="pt-reise__label">Zahlung</div>
            <?php if ($anz > 0): ?>
              <div class="pt-zahlung<?= (int) $b['anzahlung_bezahlt'] === 1 ? ' ist-bezahlt' : '' ?>">
                <span>Anzahlung<span class="pt-zahlung__stand"><?= (int) $b['anzahlung_bezahlt'] === 1
                  ? 'bezahlt' : ($bestaetigt ? 'offen' : 'nach der Bestätigung') ?></span></span>
                <span class="pt-zahlung__betrag"><?= Util::h(Util::geldKurz($anz, $w)) ?></span>
              </div>
            <?php endif; ?>
            <div class="pt-zahlung<?= (int) $b['bezahlt'] === 1 ? ' ist-bezahlt' : '' ?>">
              <span><?= $anz > 0 ? 'Restzahlung' : 'Reisepreis' ?><span class="pt-zahlung__stand"><?= (int) $b['bezahlt'] === 1
                ? 'bezahlt' : 'bis ' . Util::h(Util::datum((string) $b['rest_faellig'])) ?></span></span>
              <span class="pt-zahlung__betrag"><?= Util::h(Util::geldKurz($summe - $anz, $w)) ?></span>
            </div>
            <div class="pt-zahlung pt-zahlung--summe">
              <span>Gesamt</span><span class="pt-zahlung__betrag"><?= Util::h(Util::geldKurz($summe, $w)) ?></span>
            </div>
            <?php if ($offen && $bank !== ''): ?>
              <div class="pt-reise__bank">
                <strong>Überweisung an</strong><br><?= nl2br(Util::h($bank)) ?><br>
                <span class="gedimmt">Verwendungszweck: <?= Util::h((string) $r['titel'] . ' · ' . (string) $b['name']) ?></span>
              </div>
            <?php endif; ?>
          </div>
        </div>

        <div class="pt-reise__fuss">
          <?php if ((string) $r['status'] === Trips::OEFFENTLICH): ?>
            <a class="btn btn--klein btn--rand" href="<?= Util::attr(Reiseseite::adresse($r)) ?>" target="_blank" rel="noopener">
              Reise ansehen</a>
          <?php endif; ?>
          <a class="btn btn--klein btn--rand" href="<?= Util::attr(App::url('/portal/?ansicht=nachrichten')) ?>">
            Frage zur Reise</a>
        </div>
      </div>
    </article>
  <?php endforeach; ?>

  <?php if ($meineReisen['vergangen'] !== []): ?>
    <h2 class="abschnitt-titel">Frühere Reisen</h2>
    <div class="pt-liste">
      <?php foreach ($meineReisen['vergangen'] as ['buchung' => $b, 'reise' => $r]): ?>
        <div class="pt-zeile">
          <span class="pt-zeile__text">
            <span class="pt-zeile__titel"><?= Util::h((string) $r['titel']) ?></span>
            <span class="pt-zeile__unter"><?= Util::h(Reiseseite::zeitraum((string) $r['start'], (string) $r['ende'])) ?>
              · <?= (int) $b['personen'] ?> <?= (int) $b['personen'] === 1 ? 'Person' : 'Personen' ?></span>
          </span>
          <?php if ((string) $b['status'] === 'storniert'): ?><?= pille('Storniert') ?><?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

<?php
/* ======================================================== Unterlagen === */
elseif ($ansicht === 'unterlagen'):
  $dokumente = array_filter($akte['dokumente'], static fn ($d) => (int) $d['sichtbar_portal'] === 1);
  $aktivePakete = array_filter($akte['pakete'], static fn ($p) => (string) $p['status'] === 'aktiv');
?>
  <?php if ($aktivePakete !== []): ?>
    <h2 class="mb-3" style="font-size:15px">Deine Pakete</h2>
    <div class="stapel stapel--eng mb-5">
      <?php foreach ($aktivePakete as $p):
        $offen = (int) $p['einheiten_gesamt'] - (int) $p['einheiten_genutzt'];
        $tage  = Util::tageBis((string) $p['laeuft_ab']); ?>
        <div class="karte"><div class="karte__koerper">
          <div class="reihe reihe--zwischen mb-2">
            <span class="halbfett"><?= Util::h((string) $p['name']) ?></span>
            <?= pille($offen . ' von ' . (int) $p['einheiten_gesamt'] . ' offen',
                  $offen > 0 ? 'marke' : '') ?>
          </div>
          <?= balken((int) round(((int) $p['einheiten_genutzt'] / max(1, (int) $p['einheiten_gesamt'])) * 100)) ?>
          <div class="winzig gedimmt mt-2">
            <?= $tage > 0
                ? 'Gültig noch ' . $tage . ' Tage, bis ' . Util::h(Util::datum((string) $p['laeuft_ab']))
                : 'Abgelaufen am ' . Util::h(Util::datum((string) $p['laeuft_ab'])) ?>
          </div>
        </div></div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php if ($akte['gutscheine'] !== []): ?>
    <h2 class="mb-3" style="font-size:15px">Gutscheine</h2>
    <div class="pt-liste mb-5">
      <?php foreach ($akte['gutscheine'] as $g): ?>
        <div class="pt-zeile">
          <span class="pt-zeile__text">
            <span class="pt-zeile__titel mono"><?= Util::h((string) $g['code']) ?></span>
            <span class="pt-zeile__unter">
              <?= (string) $g['art'] === 'wert'
                  ? 'Restwert ' . Util::h(Util::geld((int) $g['rest_cent']))
                  : Util::h((string) $g['leistung']) ?></span>
          </span>
          <?= pille((string) $g['status'], (string) $g['status'] === 'aktiv' ? 'erfolg' : '') ?>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <h2 class="mb-3" style="font-size:15px">Rechnungen</h2>
  <?php if ($akte['rechnungen'] === []): ?>
    <div class="karte mb-5"><div class="karte__koerper gedimmt klein">Noch keine Rechnungen.</div></div>
  <?php else: ?>
    <div class="pt-liste mb-5">
      <?php foreach ($akte['rechnungen'] as $r): ?>
        <div class="pt-zeile">
          <span class="pt-zeile__text">
            <span class="pt-zeile__titel mono"><?= Util::h((string) $r['nummer']) ?></span>
            <span class="pt-zeile__unter"><?= Util::h(Util::datum((string) $r['datum'])) ?>
              · <?= Util::h(Util::geld((int) $r['summe_cent'])) ?></span>
          </span>
          <?= pille((string) $r['status'],
                (string) $r['status'] === 'bezahlt' ? 'erfolg'
                : ((string) $r['status'] === 'ueberfaellig' ? 'gefahr' : 'offen')) ?>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <h2 class="mb-3" style="font-size:15px">Dokumente</h2>
  <?php if ($dokumente === []): ?>
    <div class="karte"><div class="karte__koerper gedimmt klein">
      Hier legt dein Trainer Unterlagen ab – zum Beispiel eine Schlägeranpassung
      oder die Platzreife-Urkunde.</div></div>
  <?php else: ?>
    <div class="pt-liste">
      <?php foreach ($dokumente as $d): ?>
        <a class="pt-zeile" href="<?= Util::attr(App::url('/datei.php?art=dokument&id=' . (int) $d['id'])) ?>" target="_blank" rel="noopener">
          <span style="color:var(--text-3);display:flex"><?= Icon::svg('folder', 17) ?></span>
          <span class="pt-zeile__text">
            <span class="pt-zeile__titel"><?= Util::h((string) $d['titel']) ?></span>
            <span class="pt-zeile__unter"><?= Util::h(Util::datum((string) $d['erstellt'])) ?>
              · <?= Util::h(Util::bytes((int) $d['groesse'])) ?></span>
          </span>
          <?= Icon::svg('download', 15) ?>
        </a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

<?php
/* ====================================================== Nachrichten === */
elseif ($ansicht === 'nachrichten'):
?>
  <form method="post" class="karte mb-5">
    <?= Auth::csrfFeld() ?><input type="hidden" name="aktion" value="nachricht">
    <div class="karte__koerper">
      <div class="feld"><label class="feld__label" for="n-text">Nachricht an deinen Trainer</label>
        <textarea class="eingabe" id="n-text" name="text" rows="3" data-waechst required
                  placeholder="Kannst du mir nächste Woche einen Termin am Abend geben?"></textarea></div>
    </div>
    <div class="karte__fuss"><div class="fueller"></div>
      <button class="btn btn--primaer" type="submit"><?= Icon::svg('send', 15) ?> Senden</button></div>
  </form>

  <div class="pt-liste">
    <?php foreach ($akte['nachrichten'] as $n): ?>
      <div class="pt-zeile" style="align-items:flex-start">
        <span style="color:var(--text-4);display:flex;padding-top:2px">
          <?= Icon::svg((string) $n['richtung'] === 'eingehend' ? 'arrow-up' : 'arrow-down', 15) ?></span>
        <span class="pt-zeile__text">
          <span class="pt-zeile__titel"><?= Util::h((string) $n['betreff']) ?></span>
          <span class="pt-zeile__unter" style="white-space:pre-wrap"><?= Util::h(Util::kuerzen((string) $n['text'], 220)) ?></span>
          <span class="winzig gedimmt"><?= Util::h(Util::datumZeit((string) $n['erstellt'])) ?></span>
        </span>
      </div>
    <?php endforeach; ?>
    <?php if ($akte['nachrichten'] === []): ?>
      <div class="pt-zeile"><span class="gedimmt klein">Noch keine Nachrichten.</span></div>
    <?php endif; ?>
  </div>

<?php
/* =========================================================== Profil === */
else:
?>
  <form method="post" class="karte mb-4">
    <?= Auth::csrfFeld() ?><input type="hidden" name="aktion" value="profil">
    <div class="karte__koerper">
      <div class="feld-reihe feld-reihe--2">
        <div class="feld"><label class="feld__label">Name</label>
          <input class="eingabe" value="<?= Util::attr(Customers::name($kunde)) ?>" disabled>
          <div class="feld__hinweis">Namensänderung bitte über deinen Trainer.</div></div>
        <div class="feld"><label class="feld__label">E-Mail</label>
          <input class="eingabe" value="<?= Util::attr((string) $kunde['email']) ?>" disabled></div>
      </div>
      <div class="feld-reihe feld-reihe--2">
        <div class="feld"><label class="feld__label" for="pr-telefon">Telefon</label>
          <input class="eingabe" id="pr-telefon" name="telefon"
                 value="<?= Util::attr((string) $kunde['telefon']) ?>"></div>
        <div class="feld"><label class="feld__label" for="pr-hcp">Mein Handicap</label>
          <input class="eingabe" id="pr-hcp" name="hcp" value="<?= Util::attr((string) $kunde['hcp'] !== '' ? Util::hcp((string) $kunde['hcp']) : '') ?>"
                 placeholder="24,3">
          <div class="feld__hinweis">Du kennst deinen Stand am besten – trag ihn gern selbst nach.</div></div>
      </div>
      <div class="feld"><label class="feld__label" for="pr-strasse">Straße</label>
        <input class="eingabe" id="pr-strasse" name="strasse"
               value="<?= Util::attr((string) $kunde['strasse']) ?>"></div>
      <div class="feld-reihe feld-reihe--2">
        <div class="feld"><label class="feld__label" for="pr-plz">PLZ</label>
          <input class="eingabe" id="pr-plz" name="plz" value="<?= Util::attr((string) $kunde['plz']) ?>"></div>
        <div class="feld"><label class="feld__label" for="pr-ort">Ort</label>
          <input class="eingabe" id="pr-ort" name="ort" value="<?= Util::attr((string) $kunde['ort']) ?>"></div>
      </div>
      <div class="feld"><label class="feld__label" for="pr-ziele">Woran willst du arbeiten?</label>
        <textarea class="eingabe" id="pr-ziele" name="ziele" rows="2" data-waechst><?= Util::h((string) $kunde['ziele']) ?></textarea>
        <div class="feld__hinweis">Dein Trainer sieht das und richtet den Plan danach aus.</div></div>
      <?php if (Kundenlogin::hatPasswort($kunde)): ?>
        <div class="feld"><label class="feld__label" for="pr-passwort-alt">Bisheriges Passwort</label>
          <input class="eingabe" id="pr-passwort-alt" type="password" name="passwort_alt"
                 autocomplete="current-password" placeholder="nur nötig, wenn du das Passwort änderst">
          <div class="feld__hinweis">Fragen wir ab, damit niemand an einem offenen Rechner
            dein Passwort austauschen kann.</div></div>
      <?php endif; ?>
      <div class="feld"><label class="feld__label" for="pr-passwort">Neues Passwort</label>
        <input class="eingabe" id="pr-passwort" type="password" name="passwort" autocomplete="new-password"
               placeholder="leer lassen, um es nicht zu ändern">
        <div class="feld__hinweis">Mit einem Passwort kommst du auch ohne den Link deines Trainers hinein.</div></div>
      <label class="haken">
        <input type="checkbox" name="newsletter" value="1"<?= (int) $kunde['newsletter'] === 1 ? ' checked' : '' ?>>
        <span class="haken__text">Newsletter erhalten
          <span class="haken__hinweis">Tipps, Termine und Angebote. Jederzeit abbestellbar.</span></span>
      </label>
    </div>
    <div class="karte__fuss"><div class="fueller"></div>
      <button class="btn btn--primaer" type="submit">Speichern</button></div>
  </form>

  <div class="karte">
    <div class="karte__kopf"><h2>Meine Daten</h2></div>
    <div class="karte__koerper">
      <p class="klein gedimmt mb-4">Du hast das Recht, eine Kopie aller zu dir gespeicherten
        Daten zu bekommen (Artikel 15 und 20 DSGVO) und ihre Löschung zu verlangen
        (Artikel 17 DSGVO). Beide Anfragen werden innerhalb eines Monats beantwortet.</p>
      <div class="reihe reihe--eng reihe--umbruch">
        <form method="post" class="inline">
          <?= Auth::csrfFeld() ?><input type="hidden" name="aktion" value="datenanfrage">
          <input type="hidden" name="typ" value="export">
          <button class="btn btn--klein" type="submit"><?= Icon::svg('download', 14) ?> Datenkopie anfordern</button>
        </form>
        <form method="post" class="inline"
              data-bestaetigen="Löschung deiner Daten beantragen? Bezahlte Rechnungen müssen aus steuerlichen Gründen aufbewahrt werden.">
          <?= Auth::csrfFeld() ?><input type="hidden" name="aktion" value="datenanfrage">
          <input type="hidden" name="typ" value="loeschung">
          <button class="btn btn--klein" type="submit"><?= Icon::svg('trash', 14) ?> Löschung beantragen</button>
        </form>
      </div>
    </div>
  </div>
<?php endif; ?>

<?php require __DIR__ . '/partials/fuss.php'; ?>
