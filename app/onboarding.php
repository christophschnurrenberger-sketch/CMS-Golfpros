<?php
/**
 * Einrichtung in neun Schritten.
 *
 * Die Regel dahinter: Am Ende steht keine Checkliste, sondern eine Website,
 * die online ist und Buchungen annimmt. Deshalb legt der letzte Schritt
 * Seiten, Leistungen, Verfügbarkeiten und Rechtsseiten wirklich an – statt
 * nur Haken zu setzen und den Rest dem Anwender zu überlassen.
 *
 * Jeder Schritt lässt sich überspringen. Was fehlt, ergänzt später die
 * Empfehlungsliste auf dem Dashboard.
 */
require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/partials/helfer.php';

Auth::fordern('modul.settings');
Auth::fordern('settings.allgemein');

const SCHRITTE = [
    1 => ['Art des Betriebs',   'Einzelner Pro oder Academy – davon hängt ab, was du siehst.'],
    2 => ['Name und Kontakt',   'Wie du auf Website, Rechnungen und in E-Mails heißt.'],
    3 => ['Standort',           'Wo trainiert wird.'],
    4 => ['Leistungen',         'Was gebucht werden kann und was es kostet.'],
    5 => ['Zeiten',             'Wann du unterrichtest.'],
    6 => ['Schwerpunkt',        'Wen du vor allem trainierst – das prägt die Texte.'],
    7 => ['Aussehen',           'Farbe und Schrift deiner Website.'],
    8 => ['Rechtliches',        'Impressum, Datenschutz und Stornofrist.'],
    9 => ['Fertig',             'Website erzeugen und veröffentlichen.'],
];

$w       = Tenant::workspace() ?? [];
$schritt = max(1, min(9, App::getInt('schritt', max(1, (int) ($w['onboarding_schritt'] ?? 0) + 1))));

/* --------------------------------------------------------- Speichern --- */

if (App::istPost()) {
    Auth::csrfFordern();
    $schritt = max(1, min(9, App::postInt('schritt', 1)));
    $weiter  = $schritt + 1;

    if ($schritt === 1) {
        Tenant::aktualisieren(['typ' => App::post('typ', 'pro') === 'academy' ? 'academy' : 'pro']);
    }

    if ($schritt === 2) {
        Tenant::aktualisieren([
            'name'   => App::post('name', (string) ($w['name'] ?? '')),
            'domain' => strtolower(preg_replace('/^https?:\/\/|^www\.|\/$/', '', App::post('domain')) ?? ''),
        ]);
        Tenant::einstellungSetzen('website_beschreibung', App::post('beschreibung'));
        Tenant::einstellungSetzen('mail_absender', App::post('email'));
        Tenant::einstellungSetzen('telefon', App::post('telefon'));
        Tenant::update('users', Auth::id(), ['titel' => App::post('titel')]);
    }

    if ($schritt === 3 && App::post('name') !== '') {
        $vorhanden = Tenant::one('locations', 'name = :n', ['n' => App::post('name')]);
        $daten = [
            'name'    => App::post('name'),
            'typ'     => App::post('typ', 'club'),
            'strasse' => App::post('strasse'),
            'plz'     => App::post('plz'),
            'ort'     => App::post('ort'),
            'aktiv'   => 1,
        ];
        if ($vorhanden !== null) {
            Tenant::update('locations', (int) $vorhanden['id'], $daten);
        } else {
            Tenant::insert('locations', $daten + ['erstellt' => Util::jetzt()]);
        }
    }

    if ($schritt === 4) {
        $namen   = App::postListe('l_name');
        $dauern  = App::postListe('l_dauer');
        $preise  = App::postListe('l_preis');
        $ort     = Tenant::one('locations', 'aktiv = 1');
        $position = 0;
        foreach ($namen as $i => $name) {
            $name = trim((string) $name);
            if ($name === '') {
                continue;
            }
            $slug = Util::slug($name);
            if (Tenant::count('services', 'slug = :s', ['s' => $slug]) > 0) {
                continue;   // schon angelegt – ein zweiter Durchlauf darf nichts verdoppeln
            }
            Tenant::insert('services', [
                'name' => $name, 'slug' => $slug, 'art' => 'einzel',
                'dauer_min' => max(15, (int) ($dauern[$i] ?? 60)),
                'preis_cent' => Util::centAus((string) ($preise[$i] ?? '0')),
                'kapazitaet' => 1,
                'location_id' => (int) ($ort['id'] ?? 0),
                'online_buchbar' => 1, 'aktiv' => 1,
                'position' => $position++,
                'erstellt' => Util::jetzt(),
            ]);
        }
    }

    if ($schritt === 5) {
        $von = Util::zeitZuMinuten(App::post('von', '09:00'));
        $bis = Util::zeitZuMinuten(App::post('bis', '18:00'));
        $tage = array_map('intval', App::postListe('tage'));
        Tenant::deleteWhere('availability', 'user_id = :u', ['u' => Auth::id()]);
        foreach ($tage as $tag) {
            if ($tag < 0 || $tag > 6 || $bis <= $von) {
                continue;
            }
            Tenant::insert('availability', [
                'user_id' => Auth::id(), 'location_id' => 0,
                'wochentag' => $tag, 'von_min' => $von, 'bis_min' => $bis, 'aktiv' => 1,
            ]);
        }
    }

    if ($schritt === 6) {
        Tenant::einstellungSetzen('schwerpunkt', App::post('schwerpunkt', 'allgemein'));
    }

    if ($schritt === 7) {
        $branding = Tenant::branding();
        $branding['primaer'] = App::post('primaer', $branding['primaer']);
        $branding['akzent']  = App::post('akzent', $branding['akzent']);
        $branding['schrift'] = App::post('schrift', $branding['schrift']);
        Tenant::aktualisieren(['branding' => Util::json($branding)]);
    }

    if ($schritt === 8) {
        Tenant::einstellungSetzen('rechnung_absender', App::post('anschrift'));
        Tenant::einstellungSetzen('steuernummer', App::post('steuernummer'));
        Tenant::einstellungSetzen('kleinunternehmer', App::postBool('kleinunternehmer'));
        Tenant::einstellungSetzen('stornofrist_stunden', App::postInt('stornofrist_stunden', 24));
    }

    if ($schritt === 9) {
        $erzeugt = websiteErzeugen();
        Tenant::aktualisieren(['onboarding_schritt' => 9]);
        Audit::schreiben('erstellt', 'workspace', Tenant::id(), 'Einrichtung abgeschlossen');
        App::melden('Fertig. ' . $erzeugt . ' – die Website ist online.');
        App::weiter('/app/');
    }

    Tenant::aktualisieren(['onboarding_schritt' => max((int) ($w['onboarding_schritt'] ?? 0), $schritt)]);
    App::weiter('/app/onboarding.php?schritt=' . $weiter);
}

/**
 * Legt die Startseite und die Pflichtseiten an – aber nur, was fehlt.
 * Ein zweiter Durchlauf der Einrichtung darf keine Doppel erzeugen.
 */
function websiteErzeugen(): string
{
    $schwerpunkt = (string) Tenant::einstellung('schwerpunkt', 'allgemein');
    $getan = [];

    if (Pages::startseite() === null) {
        $id = Pages::speichern([
            'titel'  => 'Startseite',
            'slug'   => 'start',
            'art'    => 'seite',
            'status' => 'veroeffentlicht',
            'im_menue' => 0,
            'bloecke' => Util::json(Pages::vorlage(Tenant::name(), $schwerpunkt)),
        ]);
        Pages::startseiteSetzen($id);
        $getan[] = 'Startseite angelegt';
    }

    foreach ([
        'ueber-mich'  => ['Über mich', 'seite'],
        'leistungen'  => ['Leistungen & Preise', 'seite'],
        'kontakt'     => ['Kontakt', 'seite'],
    ] as $slug => [$titel, $art]) {
        if (Tenant::count('pages', 'slug = :s', ['s' => $slug]) > 0) {
            continue;
        }
        $bloecke = match ($slug) {
            'leistungen' => [Bloecke::neu('ueberschrift'), Bloecke::neu('preise'), Bloecke::neu('buchung')],
            'kontakt'    => [Bloecke::neu('ueberschrift'), Bloecke::neu('kontakt')],
            default      => [Bloecke::neu('ueberschrift'), Bloecke::neu('team'), Bloecke::neu('text')],
        };
        Pages::speichern([
            'titel' => $titel, 'slug' => $slug, 'art' => $art,
            'status' => 'veroeffentlicht', 'im_menue' => 1,
            'bloecke' => Util::json($bloecke),
        ]);
        $getan[] = $titel;
    }

    foreach (['impressum' => 'Impressum', 'datenschutz' => 'Datenschutzerklärung',
              'agb' => 'AGB'] as $slug => $titel) {
        if (Tenant::count('pages', 'slug = :s', ['s' => $slug]) > 0) {
            continue;
        }
        $text = Bloecke::neu('text');
        $text['daten']['text'] = rechtsgeruest($slug);
        Pages::speichern([
            'titel' => $titel, 'slug' => $slug, 'art' => 'seite',
            'status' => 'veroeffentlicht', 'im_menue' => 0,
            'bloecke' => Util::json([$text]),
        ]);
        $getan[] = $titel;
    }

    Segments::standardAnlegen();

    return count($getan) > 0
        ? implode(', ', array_slice($getan, 0, 3)) . (count($getan) > 3 ? ' und ' . (count($getan) - 3) . ' weitere' : '')
        : 'Alles war schon vorhanden';
}

/** Textgerüst, ausdrücklich zum Ausfüllen – keine fertige Rechtserklärung. */
function rechtsgeruest(string $slug): string
{
    $name = Tenant::name();
    $anschrift = (string) Tenant::einstellung('rechnung_absender', '[Anschrift]');
    return match ($slug) {
        'impressum' => "**Angaben gemäß § 5 DDG**\n\n" . $name . "\n" . $anschrift
            . "\n\nVertreten durch: [Name]\nTelefon: [Telefon]\nE-Mail: "
            . (string) Tenant::einstellung('mail_absender', '[E-Mail]')
            . "\nUmsatzsteuer-Identifikationsnummer: [USt-IdNr.]"
            . "\n\n*Dieses Gerüst ist noch auszufüllen und zu prüfen.*",
        'datenschutz' => "**Datenschutzerklärung**\n\nVerantwortlich für die Verarbeitung "
            . "personenbezogener Daten auf dieser Website ist " . $name . ", " . $anschrift . ".\n\n"
            . "**Welche Daten verarbeitet werden**\n\nBeim Aufruf dieser Website werden keine "
            . "Cookies gesetzt. Zur Reichweitenmessung wird aus der IP-Adresse zusammen mit einem "
            . "täglich wechselnden Zufallswert eine Prüfsumme gebildet; die IP-Adresse selbst wird "
            . "nicht gespeichert und ein Rückschluss auf einzelne Personen ist nicht möglich.\n\n"
            . "Wer einen Termin bucht oder ein Formular absendet, übermittelt Name, E-Mail-Adresse "
            . "und die im Formular angegebenen Daten. Sie werden zur Durchführung des Trainings und "
            . "zur Abrechnung verarbeitet (Artikel 6 Absatz 1 Buchstabe b DSGVO).\n\n"
            . "**Deine Rechte**\n\nAuskunft, Berichtigung, Löschung, Einschränkung, "
            . "Datenübertragbarkeit und Widerspruch – eine kurze Nachricht genügt.\n\n"
            . "*Dieses Gerüst ist noch auf den eigenen Betrieb anzupassen und zu prüfen.*",
        default => "**Allgemeine Geschäftsbedingungen**\n\n**1. Geltung**\n\nDiese Bedingungen "
            . "gelten für alle Trainingsleistungen von " . $name . ".\n\n"
            . "**2. Buchung und Zahlung**\n\nEine Buchung kommt mit der Bestätigung zustande. "
            . "Die Zahlung ist [vor der Einheit / innerhalb von 14 Tagen] fällig.\n\n"
            . "**3. Absage**\n\nTermine können bis "
            . (int) Tenant::einstellung('stornofrist_stunden', 24) . " Stunden vor Beginn "
            . "kostenfrei abgesagt werden. Danach wird die Einheit berechnet.\n\n"
            . "**4. Gutscheine und Pakete**\n\nPakete sind [12] Monate ab Kauf gültig.\n\n"
            . "*Dieses Gerüst ist noch auszufüllen und zu prüfen.*",
    };
}

/* ------------------------------------------------------- Darstellung --- */

$ort       = Tenant::one('locations', '', [], 'id');
$branding  = Tenant::branding();
$leistungen = Tenant::all('services', '', [], 'position', 5);
$zeiten    = Tenant::all('availability', 'user_id = :u', ['u' => Auth::id()]);
$gewaehlteTage = array_map(static fn ($z) => (int) $z['wochentag'], $zeiten);
$ich       = Auth::benutzer() ?? [];

$titel = 'Einrichtung';
$unter = 'Schritt ' . $schritt . ' von 9 · ' . SCHRITTE[$schritt][1];
$ohneKopf = false;
require __DIR__ . '/partials/kopf.php';
?>

<div class="schritte mb-5">
  <?php foreach (SCHRITTE as $n => [$name, $beschreibung]):
    $erledigt = $n < $schritt;
    $jetzt    = $n === $schritt; ?>
    <a class="schritt<?= $jetzt ? ' ist-aktiv' : '' ?><?= $erledigt ? ' ist-fertig' : '' ?>"
       href="<?= Util::attr(App::url('/app/onboarding.php?schritt=' . $n)) ?>">
      <span class="schritt__zahl"><?= $erledigt ? Icon::svg('check', 13) : $n ?></span>
      <span class="schritt__name"><?= Util::h($name) ?></span>
      <?php if ($n < 9): ?><span class="schritt__strich"></span><?php endif; ?>
    </a>
  <?php endforeach; ?>
</div>

<form method="post" class="karte" style="max-width:820px;margin:0 auto">
  <?= Auth::csrfFeld() ?>
  <input type="hidden" name="schritt" value="<?= $schritt ?>">
  <div class="karte__kopf">
    <h2><?= Util::h(SCHRITTE[$schritt][0]) ?></h2>
  </div>
  <div class="karte__koerper">

    <?php if ($schritt === 1): ?>
      <p class="gedimmt mb-4">Danach ist der Rest schneller: Ein einzelner Pro sieht kein
        Teammenü, eine Academy dafür Trainerzuordnung und Standorte.</p>
      <div class="wahl-karten">
        <label class="wahl-karte">
          <input type="radio" name="typ" value="pro" <?= (string) ($w['typ'] ?? 'pro') !== 'academy' ? 'checked' : '' ?>>
          <span class="wahl-karte__titel"><?= Icon::svg('user', 18) ?> Ich unterrichte allein</span>
          <span class="wahl-karte__text">Ein Kalender, eine Preisliste, eine Website. Weniger
            Einstellungen, schneller startklar.</span>
        </label>
        <label class="wahl-karte">
          <input type="radio" name="typ" value="academy" <?= (string) ($w['typ'] ?? '') === 'academy' ? 'checked' : '' ?>>
          <span class="wahl-karte__titel"><?= Icon::svg('customers', 18) ?> Wir sind mehrere</span>
          <span class="wahl-karte__text">Mehrere Trainer, gemeinsame Kundenakte, Termine je
            Trainer und Standort.</span>
        </label>
      </div>

    <?php elseif ($schritt === 2): ?>
      <div class="feld-reihe feld-reihe--2">
        <div class="feld"><label class="feld__label" for="f-name">Name deines Betriebs</label>
          <input class="eingabe" id="f-name" name="name" required
                 value="<?= Util::attr((string) ($w['name'] ?? '')) ?>"
                 placeholder="Golfschule Mustermann">
          <div class="feld__hinweis">Steht auf der Website, auf Rechnungen und in E-Mails.</div></div>
        <div class="feld"><label class="feld__label" for="f-titel">Deine Berufsbezeichnung</label>
          <input class="eingabe" id="f-titel" name="titel"
                 value="<?= Util::attr((string) ($ich['titel'] ?? '')) ?>"
                 placeholder="PGA Golf Professional"></div>
      </div>
      <div class="feld"><label class="feld__label" for="f-beschreibung">In einem Satz: was bietest du an?</label>
        <input class="eingabe" id="f-beschreibung" name="beschreibung"
               value="<?= Util::attr((string) Tenant::einstellung('website_beschreibung', '')) ?>"
               placeholder="Golftraining für Einsteiger und Fortgeschrittene in Karlsruhe.">
        <div class="feld__hinweis">Dieser Satz erscheint bei Google unter deinem Namen.</div></div>
      <div class="feld-reihe feld-reihe--3">
        <div class="feld"><label class="feld__label" for="f-email">E-Mail</label>
          <input class="eingabe" id="f-email" type="email" name="email"
                 value="<?= Util::attr((string) Tenant::einstellung('mail_absender', (string) ($ich['email'] ?? ''))) ?>"></div>
        <div class="feld"><label class="feld__label" for="f-telefon">Telefon</label>
          <input class="eingabe" id="f-telefon" name="telefon"
                 value="<?= Util::attr((string) Tenant::einstellung('telefon', '')) ?>"></div>
        <div class="feld"><label class="feld__label" for="f-domain">Domain (falls vorhanden)</label>
          <input class="eingabe" id="f-domain" name="domain"
                 value="<?= Util::attr((string) ($w['domain'] ?? '')) ?>"
                 placeholder="golfschule-mustermann.de"></div>
      </div>

    <?php elseif ($schritt === 3): ?>
      <p class="gedimmt mb-4">Ein Standort genügt für den Anfang. Weitere kommen später
        unter Einstellungen dazu.</p>
      <div class="feld-reihe feld-reihe--2">
        <div class="feld"><label class="feld__label" for="f-oname">Name des Standorts</label>
          <input class="eingabe" id="f-oname" name="name"
                 value="<?= Util::attr((string) ($ort['name'] ?? '')) ?>"
                 placeholder="Golfclub Sonnenhof"></div>
        <div class="feld"><label class="feld__label" for="f-otyp">Art</label>
          <select id="f-otyp" name="typ">
            <?php foreach (['club' => 'Golfclub', 'range' => 'Driving Range',
                            'indoor' => 'Indoor / Simulator', 'platz' => 'Platz'] as $k => $v): ?>
              <option value="<?= $k ?>"<?= (string) ($ort['typ'] ?? 'club') === $k ? ' selected' : '' ?>>
                <?= Util::h($v) ?></option>
            <?php endforeach; ?>
          </select></div>
      </div>
      <div class="feld"><label class="feld__label" for="f-strasse">Straße und Hausnummer</label>
        <input class="eingabe" id="f-strasse" name="strasse"
               value="<?= Util::attr((string) ($ort['strasse'] ?? '')) ?>"></div>
      <div class="feld-reihe feld-reihe--2">
        <div class="feld"><label class="feld__label" for="f-plz">PLZ</label>
          <input class="eingabe" id="f-plz" name="plz" value="<?= Util::attr((string) ($ort['plz'] ?? '')) ?>"></div>
        <div class="feld"><label class="feld__label" for="f-ort">Ort</label>
          <input class="eingabe" id="f-ort" name="ort" value="<?= Util::attr((string) ($ort['ort'] ?? '')) ?>"></div>
      </div>

    <?php elseif ($schritt === 4): ?>
      <p class="gedimmt mb-4">Drei Vorschläge zum Anpassen. Was du hier einträgst, steht sofort
        auf der Website und in der Online-Buchung. Leere Zeilen werden übersprungen.</p>
      <?php
      $vorschlaege = [
          ['Einzeltraining 60 Minuten', 60, '75,00'],
          ['Einzeltraining 30 Minuten', 30, '42,00'],
          ['Platzreifekurs (Gruppe)', 120, '349,00'],
      ];
      foreach ($vorschlaege as $i => [$name, $dauer, $preis]):
        $vorhanden = $leistungen[$i] ?? null; ?>
        <div class="feld-reihe feld-reihe--3 mb-2">
          <div class="feld"><label class="feld__label" for="l-n<?= $i ?>">Leistung <?= $i + 1 ?></label>
            <input class="eingabe" id="l-n<?= $i ?>" name="l_name[]"
                   value="<?= Util::attr((string) ($vorhanden['name'] ?? $name)) ?>"></div>
          <div class="feld"><label class="feld__label" for="l-d<?= $i ?>">Dauer</label>
            <div class="eingabe-gruppe">
              <input class="eingabe" id="l-d<?= $i ?>" type="number" name="l_dauer[]" min="15" step="5"
                     value="<?= (int) ($vorhanden['dauer_min'] ?? $dauer) ?>">
              <span class="eingabe-gruppe__anhang">Min</span>
            </div></div>
          <div class="feld"><label class="feld__label" for="l-p<?= $i ?>">Preis</label>
            <div class="eingabe-gruppe">
              <input class="eingabe" id="l-p<?= $i ?>" name="l_preis[]"
                     value="<?= Util::attr($vorhanden
                         ? number_format((int) $vorhanden['preis_cent'] / 100, 2, ',', '')
                         : $preis) ?>">
              <span class="eingabe-gruppe__anhang"><?= Util::h(Tenant::waehrung()) ?></span>
            </div></div>
        </div>
      <?php endforeach; ?>

    <?php elseif ($schritt === 5): ?>
      <p class="gedimmt mb-4">Grobe Zeiten genügen. Einzelne Ausnahmen, Urlaub und
        Blockzeiten trägst du später im Kalender ein.</p>
      <div class="feld">
        <span class="feld__label">An welchen Tagen unterrichtest du?</span>
        <div class="reihe reihe--eng reihe--umbruch">
          <?php foreach ([1 => 'Mo', 2 => 'Di', 3 => 'Mi', 4 => 'Do', 5 => 'Fr', 6 => 'Sa', 0 => 'So'] as $n => $kurz):
            $an = $zeiten === [] ? ($n >= 1 && $n <= 5) : in_array($n, $gewaehlteTage, true); ?>
            <label class="haken"><input type="checkbox" name="tage[]" value="<?= $n ?>"<?= $an ? ' checked' : '' ?>>
              <span class="haken__text"><?= $kurz ?></span></label>
          <?php endforeach; ?>
        </div>
      </div>
      <div class="feld-reihe feld-reihe--2 mt-3" style="max-width:400px">
        <div class="feld"><label class="feld__label" for="f-von">Von</label>
          <input class="eingabe" id="f-von" type="time" name="von"
                 value="<?= Util::attr(Util::minutenZuZeit((int) ($zeiten[0]['von_min'] ?? 540))) ?>"></div>
        <div class="feld"><label class="feld__label" for="f-bis">Bis</label>
          <input class="eingabe" id="f-bis" type="time" name="bis"
                 value="<?= Util::attr(Util::minutenZuZeit((int) ($zeiten[0]['bis_min'] ?? 1080))) ?>"></div>
      </div>

    <?php elseif ($schritt === 6): ?>
      <p class="gedimmt mb-4">Die Auswahl bestimmt die Überschriften und Texte, die auf deiner
        Website vorgeschlagen werden – ändern kannst du sie jederzeit.</p>
      <div class="wahl-karten">
        <?php
        $aktuell = (string) Tenant::einstellung('schwerpunkt', 'allgemein');
        foreach ([
          'allgemein' => ['Gemischt', 'Vom Einsteiger bis zum Turnierspieler – alles dabei.'],
          'anfaenger' => ['Einsteiger und Platzreife', 'Der Schwerpunkt liegt auf den ersten Monaten im Golf.'],
          'leistung'  => ['Leistungsorientiert', 'Handicap-Ziele, Videoanalyse, Turnier­vorbereitung.'],
          'junior'    => ['Kinder und Jugendliche', 'Gruppen nach Alter, spielerisches Training.'],
        ] as $k => [$name, $text]): ?>
          <label class="wahl-karte">
            <input type="radio" name="schwerpunkt" value="<?= $k ?>"<?= $aktuell === $k ? ' checked' : '' ?>>
            <span class="wahl-karte__titel"><?= Util::h($name) ?></span>
            <span class="wahl-karte__text"><?= Util::h($text) ?></span>
          </label>
        <?php endforeach; ?>
      </div>

    <?php elseif ($schritt === 7): ?>
      <p class="gedimmt mb-4">Eine Farbe genügt – alles andere leitet sich davon ab.</p>
      <div class="feld">
        <span class="feld__label">Markenfarbe</span>
        <div class="reihe reihe--eng reihe--umbruch mb-3">
          <?php foreach (['#1d6f4a' => 'Fairway', '#0f4c81' => 'Tiefblau', '#1c1c1e' => 'Anthrazit',
                          '#7a3e2e' => 'Terrakotta', '#4a4e8c' => 'Indigo', '#2f6f6b' => 'Petrol'] as $farbe => $name): ?>
            <label class="tipp" data-tipp="<?= Util::attr($name) ?>" style="cursor:pointer">
              <input type="radio" name="primaer" value="<?= $farbe ?>" class="versteckt"
                     <?= strcasecmp((string) $branding['primaer'], $farbe) === 0 ? ' checked' : '' ?>>
              <span style="display:block;width:38px;height:38px;border-radius:10px;background:<?= $farbe ?>;
                           border:2px solid <?= strcasecmp((string) $branding['primaer'], $farbe) === 0 ? 'var(--text)' : 'transparent' ?>"></span>
            </label>
          <?php endforeach; ?>
        </div>
      </div>
      <div class="feld-reihe feld-reihe--2">
        <div class="feld"><label class="feld__label" for="f-akzent">Akzentfarbe</label>
          <input class="eingabe eingabe--farbe" id="f-akzent" type="color" name="akzent"
                 value="<?= Util::attr((string) $branding['akzent']) ?>"></div>
        <div class="feld"><label class="feld__label" for="f-schrift">Schrift</label>
          <select id="f-schrift" name="schrift">
            <?php foreach (['Inter' => 'Inter – sachlich, sehr gut lesbar',
                            'Source Serif 4' => 'Source Serif – traditionell',
                            'Outfit' => 'Outfit – modern, rund'] as $k => $v): ?>
              <option value="<?= Util::attr($k) ?>"<?= (string) $branding['schrift'] === $k ? ' selected' : '' ?>>
                <?= Util::h($v) ?></option>
            <?php endforeach; ?>
          </select></div>
      </div>

    <?php elseif ($schritt === 8): ?>
      <p class="gedimmt mb-4">Aus diesen Angaben entstehen im letzten Schritt Impressum,
        Datenschutzerklärung und AGB als Textgerüste zum Prüfen.</p>
      <div class="feld"><label class="feld__label" for="f-anschrift">Vollständige Anschrift</label>
        <input class="eingabe" id="f-anschrift" name="anschrift"
               value="<?= Util::attr((string) Tenant::einstellung('rechnung_absender', '')) ?>"
               placeholder="Max Mustermann · Musterweg 1 · 76131 Karlsruhe"></div>
      <div class="feld-reihe feld-reihe--2">
        <div class="feld"><label class="feld__label" for="f-steuernummer">Steuernummer / USt-IdNr.</label>
          <input class="eingabe" id="f-steuernummer" name="steuernummer"
                 value="<?= Util::attr((string) Tenant::einstellung('steuernummer', '')) ?>"></div>
        <div class="feld"><label class="feld__label" for="f-storno">Stornofrist</label>
          <div class="eingabe-gruppe">
            <input class="eingabe" id="f-storno" type="number" name="stornofrist_stunden" min="0"
                   value="<?= (int) Tenant::einstellung('stornofrist_stunden', 24) ?>">
            <span class="eingabe-gruppe__anhang">Stunden</span>
          </div>
          <div class="feld__hinweis">24 Stunden sind üblich und fair.</div></div>
      </div>
      <label class="haken">
        <input type="checkbox" name="kleinunternehmer" value="1"
               <?= Tenant::einstellung('kleinunternehmer', false) ? ' checked' : '' ?>>
        <span class="haken__text">Kleinunternehmerregelung (§ 19 UStG)
          <span class="haken__hinweis">Dann weist keine Rechnung Umsatzsteuer aus.</span></span>
      </label>
      <div class="hinweis hinweis--still mt-4">
        <?= Icon::svg('info', 17) ?>
        <div class="hinweis__text">Die erzeugten Rechtstexte sind Gerüste mit Platzhaltern,
          keine Rechtsberatung. Vor dem Online-Gehen sollte sie jemand mit Fachkenntnis ansehen.</div>
      </div>

    <?php else: ?>
      <div class="mitte">
        <div class="leerzustand__symbol" style="margin:0 auto 14px"><?= Icon::svg('wand', 26) ?></div>
        <h3 class="mb-2">Jetzt wird die Website gebaut</h3>
        <p class="gedimmt mb-5" style="max-width:520px;margin:0 auto 24px">
          Aus deinen Angaben entstehen eine fertige Startseite, die Seiten „Über mich“,
          „Leistungen“ und „Kontakt“ sowie Impressum, Datenschutz und AGB – alles
          veröffentlicht und sofort erreichbar. Ändern kannst du später jeden Block.
        </p>
      </div>
      <div class="raster raster--2">
        <?php foreach ([
          ['Betrieb', (string) ($w['name'] ?? '—'), 'settings', 2],
          ['Standort', (string) ($ort['name'] ?? 'noch keiner'), 'pin', 3],
          ['Leistungen', count($leistungen) > 0 ? count($leistungen) . ' angelegt' : 'noch keine', 'list', 4],
          ['Zeiten', count($zeiten) > 0 ? count($zeiten) . ' Tage' : 'noch keine', 'clock', 5],
          ['Schwerpunkt', ucfirst((string) Tenant::einstellung('schwerpunkt', 'allgemein')), 'target', 6],
          ['Farbe', (string) $branding['primaer'], 'palette', 7],
        ] as [$label, $wert, $icon, $zuSchritt]): ?>
          <a class="kachel-link" href="<?= Util::attr(App::url('/app/onboarding.php?schritt=' . $zuSchritt)) ?>">
            <span class="kachel-link__symbol"><?= Icon::svg($icon, 16) ?></span>
            <div style="flex:1;min-width:0">
              <div class="kachel-link__titel"><?= Util::h($label) ?></div>
              <div class="kachel-link__text"><?= Util::h($wert) ?></div>
            </div>
            <?= Icon::svg('edit', 14) ?>
          </a>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

  <div class="karte__fuss">
    <?php if ($schritt > 1): ?>
      <a class="btn" href="<?= Util::attr(App::url('/app/onboarding.php?schritt=' . ($schritt - 1))) ?>">
        <?= Icon::svg('arrow-left', 15) ?> Zurück</a>
    <?php endif; ?>
    <div class="fueller"></div>
    <?php if ($schritt < 9): ?>
      <a class="btn btn--geist" href="<?= Util::attr(App::url('/app/onboarding.php?schritt=' . ($schritt + 1))) ?>">
        Überspringen</a>
    <?php endif; ?>
    <button class="btn btn--primaer" type="submit">
      <?= $schritt === 9 ? 'Website erzeugen und loslegen' : 'Weiter' ?>
      <?= Icon::svg($schritt === 9 ? 'check' : 'arrow-right', 15) ?></button>
  </div>
</form>

<?php require __DIR__ . '/partials/fuss.php'; ?>
