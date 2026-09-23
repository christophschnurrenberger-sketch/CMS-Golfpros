<?php
/**
 * Neue Instanz – der Assistent.
 *
 * Sechs Schritte, ein Formular. Ohne JavaScript stehen alle Schritte
 * untereinander und es geht genauso; mit JavaScript sieht man einen nach
 * dem anderen. Geprüft wird in jedem Fall auf dem Server, vollständig,
 * erst beim Absenden – und angelegt wird alles in einer Transaktion
 * (Instanzen::anlegen). Eine halb angelegte Instanz gibt es nicht.
 */
require __DIR__ . '/partials/start.php';
Betreiber::fordern('instanzen.write');

$pakete = Pakete::aktive();
$standardPaket = (string) Plattform::einstellung('standard_paket');
if (!isset($pakete[$standardPaket])) {
    $standardPaket = (string) array_key_first($pakete);
}
$e = [
    'name' => '', 'typ' => 'pro', 'club' => '', 'ort' => '', 'waehrung' => 'EUR', 'zeitzone' => 'Europe/Berlin',
    'inhaber' => '', 'email' => '', 'telefon' => '', 'einladen' => '1',
    'paket' => $standardPaket, 'laufzeit' => 'test', 'beginn' => date('Y-m-d'),
    'test_bis' => date('Y-m-d', strtotime('+' . Plattform::zahl('test_tage') . ' days')),
    'preis_cent' => 0, 'notiz' => '', 'slug' => '', 'domain' => '',
];
$fehler = [];

if (App::istPost()) {
    Auth::csrfFordern();
    foreach (array_keys($e) as $k) {
        $e[$k] = App::post($k);
    }
    $e['einladen'] = App::postBool('einladen') ? '1' : '';
    $e['preis_cent'] = Util::centAus(App::post('preis'));
    $ergebnis = Instanzen::anlegen($e);
    if ($ergebnis['id'] > 0) {
        $name = $e['name'];
        if ($ergebnis['einladung'] === true) {
            App::melden('„' . $name . '" ist angelegt. Die Einladung an ' . strtolower($e['email']) . ' ist unterwegs.');
        } elseif ($ergebnis['einladung'] === false) {
            App::melden('„' . $name . '" ist angelegt, aber die Einladung konnte nicht verschickt werden. '
                . 'Prüfe den Mailversand und sende sie unter „Benutzer" erneut.', 'warnung');
        } else {
            App::melden('„' . $name . '" ist angelegt. Die Einladung kannst du unter „Benutzer" verschicken, wenn es so weit ist.');
        }
        App::weiter('/master/instanz.php?id=' . $ergebnis['id']);
    }
    $fehler = $ergebnis['fehler'];
}

/* In welchem Schritt steht der erste Fehler? Dorthin springt der Assistent. */
$schrittVon = ['name' => 1, 'waehrung' => 1, 'zeitzone' => 1, 'inhaber' => 2, 'email' => 2,
               'paket' => 3, 'laufzeit' => 4, 'slug' => 5, 'domain' => 5, '_' => 6];
$startSchritt = 1;
foreach ($fehler as $feld => $_) {
    $startSchritt = $schrittVon[$feld] ?? 6;
    break;
}
$feldFehler = static fn (string $feld): string => isset($fehler[$feld])
    ? '<div class="feld__fehler" id="fehler-' . $feld . '">' . Util::h($fehler[$feld]) . '</div>' : '';
$zeitzonen = ['Europe/Berlin', 'Europe/Vienna', 'Europe/Zurich', 'Europe/Amsterdam', 'Europe/Brussels',
              'Europe/Luxembourg', 'Europe/Paris', 'Europe/London', 'Europe/Dublin', 'Europe/Madrid',
              'Europe/Lisbon', 'Europe/Rome', 'Europe/Copenhagen', 'Europe/Stockholm', 'Europe/Oslo',
              'Europe/Warsaw', 'Europe/Prague', 'Atlantic/Canary'];
if (!in_array($e['zeitzone'], $zeitzonen, true)) {
    $zeitzonen[] = $e['zeitzone'];
}
$basis = App::absolut('/');

$titel = 'Neue Instanz';
$unter = 'In sechs Schritten zu einer vollständig eingerichteten Instanz – mit Inhaber, Paket und Laufzeit.';
$bereich = 'instanzen';
$brotkrumen = [['Übersicht', '/master/'], ['Instanzen', '/master/instanzen.php'], ['Neue Instanz', null]];
$skripte = '<script src="' . Util::attr(App::asset('assets/js/master.js')) . '"></script>';
require __DIR__ . '/partials/kopf.php';
$schritte = [1 => 'Grunddaten', 2 => 'Account', 3 => 'Paket', 4 => 'Laufzeit', 5 => 'Technik', 6 => 'Bestätigung'];
?>

<?php if (isset($fehler['_'])): ?>
  <div class="hinweis hinweis--gefahr mb-4"><?= Icon::svg('alert', 17) ?><div class="hinweis__text"><?= Util::h($fehler['_']) ?></div></div>
<?php elseif ($fehler !== []): ?>
  <div class="hinweis hinweis--gefahr mb-4" role="alert"><?= Icon::svg('alert', 17) ?>
    <div class="hinweis__text">Noch nicht angelegt – bitte die markierten Angaben prüfen. Es wurde nichts gespeichert.</div></div>
<?php endif; ?>

<form method="post" action="<?= Util::attr(App::url('/master/instanz-neu.php')) ?>" class="assistent" data-assistent
      data-start-schritt="<?= $startSchritt ?>" data-basis="<?= Util::attr($basis) ?>" novalidate>
  <?= Auth::csrfFeld() ?>

  <ol class="schritte mb-5 assistent__leiste" aria-label="Schritte">
    <?php foreach ($schritte as $n => $name): ?>
      <li class="schritt" data-schritt-marke="<?= $n ?>">
        <span class="schritt__zahl"><?= $n ?></span>
        <span class="schritt__name"><?= Util::h($name) ?></span>
        <?php if ($n < 6): ?><span class="schritt__strich"></span><?php endif; ?>
      </li>
    <?php endforeach; ?>
  </ol>

  <section class="karte assistent__schritt" data-schritt="1" aria-labelledby="s1">
    <div class="karte__kopf"><h2 id="s1">1 · Grunddaten</h2></div>
    <div class="karte__koerper">
      <div class="feld-reihe feld-reihe--2">
        <div class="feld"><label class="feld__label" for="n-name">Name der Instanz *</label>
          <input class="eingabe<?= isset($fehler['name']) ? ' eingabe--fehler' : '' ?>" id="n-name" name="name" required
                 minlength="2" maxlength="160" value="<?= Util::attr($e['name']) ?>" placeholder="Golfschule Muster"
                 <?= isset($fehler['name']) ? 'aria-invalid="true" aria-describedby="fehler-name"' : '' ?>>
          <?= $feldFehler('name') ?></div>
        <div class="feld"><label class="feld__label" for="n-typ">Art</label>
          <select class="eingabe" id="n-typ" name="typ">
            <?php foreach (Instanzen::TYPEN as $k => $v): ?>
              <option value="<?= $k ?>"<?= $e['typ'] === $k ? ' selected' : '' ?>><?= Util::h($v) ?></option>
            <?php endforeach; ?>
          </select></div>
      </div>
      <div class="feld-reihe feld-reihe--2">
        <div class="feld"><label class="feld__label" for="n-club">Club / Anlage</label>
          <input class="eingabe" id="n-club" name="club" maxlength="160" value="<?= Util::attr($e['club']) ?>" placeholder="GC Musterhausen">
          <div class="feld__hinweis">Wird als erster Standort der Instanz angelegt.</div></div>
        <div class="feld"><label class="feld__label" for="n-ort">Ort</label>
          <input class="eingabe" id="n-ort" name="ort" maxlength="120" value="<?= Util::attr($e['ort']) ?>"></div>
      </div>
      <div class="feld-reihe feld-reihe--3">
        <div class="feld"><label class="feld__label" for="n-waehrung">Währung</label>
          <select class="eingabe" id="n-waehrung" name="waehrung">
            <?php foreach (Instanzen::WAEHRUNGEN as $k => $v): ?>
              <option value="<?= $k ?>"<?= $e['waehrung'] === $k ? ' selected' : '' ?>><?= Util::h($k . ' · ' . $v) ?></option>
            <?php endforeach; ?>
          </select><?= $feldFehler('waehrung') ?></div>
        <div class="feld"><label class="feld__label" for="n-zeitzone">Zeitzone</label>
          <select class="eingabe" id="n-zeitzone" name="zeitzone">
            <?php foreach ($zeitzonen as $z): ?>
              <option value="<?= Util::attr($z) ?>"<?= $e['zeitzone'] === $z ? ' selected' : '' ?>><?= Util::h($z) ?></option>
            <?php endforeach; ?>
          </select><?= $feldFehler('zeitzone') ?></div>
        <div class="feld"><span class="feld__label">Sprache</span>
          <div class="eingabe eingabe--fest">Deutsch</div>
          <div class="feld__hinweis">Die Oberfläche gibt es derzeit nur auf Deutsch.</div></div>
      </div>
    </div>
  </section>

  <section class="karte assistent__schritt" data-schritt="2" aria-labelledby="s2">
    <div class="karte__kopf"><h2 id="s2">2 · Account des Golfpros</h2></div>
    <div class="karte__koerper">
      <p class="gedimmt">Der erste Benutzer wird Inhaber der Instanz. Er bekommt kein Passwort von dir, sondern einen Link,
        über den er es selbst vergibt – gültig sieben Tage.</p>
      <div class="feld-reihe feld-reihe--2">
        <div class="feld"><label class="feld__label" for="n-inhaber">Name *</label>
          <input class="eingabe<?= isset($fehler['inhaber']) ? ' eingabe--fehler' : '' ?>" id="n-inhaber" name="inhaber" required
                 maxlength="160" value="<?= Util::attr($e['inhaber']) ?>" placeholder="Max Muster"><?= $feldFehler('inhaber') ?></div>
        <div class="feld"><label class="feld__label" for="n-email">E-Mail *</label>
          <input class="eingabe<?= isset($fehler['email']) ? ' eingabe--fehler' : '' ?>" id="n-email" type="email" name="email" required
                 maxlength="190" value="<?= Util::attr($e['email']) ?>" autocomplete="off"
                 <?= isset($fehler['email']) ? 'aria-invalid="true" aria-describedby="fehler-email"' : '' ?>><?= $feldFehler('email') ?></div>
      </div>
      <div class="feld-reihe feld-reihe--2">
        <div class="feld"><label class="feld__label" for="n-telefon">Telefon</label>
          <input class="eingabe" id="n-telefon" name="telefon" maxlength="64" value="<?= Util::attr($e['telefon']) ?>"></div>
        <div class="feld"><span class="feld__label">Einladung</span>
          <label class="haken"><input type="checkbox" name="einladen" value="1"<?= $e['einladen'] !== '' ? ' checked' : '' ?>>
            <span class="haken__text">Einladung sofort senden
              <span class="haken__hinweis">Sonst später unter „Benutzer" in der Instanz.</span></span></label></div>
      </div>
    </div>
  </section>

  <section class="karte assistent__schritt" data-schritt="3" aria-labelledby="s3">
    <div class="karte__kopf"><h2 id="s3">3 · Paket</h2></div>
    <div class="karte__koerper">
      <?= $feldFehler('paket') ?>
      <div class="wahl-karten wahl-karten--pakete">
        <?php foreach ($pakete as $k => $p): ?>
          <label class="wahl-karte">
            <input type="radio" name="paket" value="<?= Util::attr($k) ?>"<?= $e['paket'] === $k ? ' checked' : '' ?> required
                   data-name="<?= Util::attr((string) $p['name']) ?>" data-monat="<?= (int) $p['preis_monat_cent'] ?>"
                   data-jahr="<?= (int) $p['preis_jahr_cent'] ?>">
            <span class="wahl-karte__titel"><?= Util::h((string) $p['name']) ?></span>
            <span class="wahl-karte__text">
              <b><?= (int) $p['preis_monat_cent'] > 0 ? Util::h(Util::geld((int) $p['preis_monat_cent'])) . ' / Monat' : 'ohne Listenpreis' ?></b>
              <?php if ((int) $p['preis_jahr_cent'] > 0): ?> · <?= Util::h(Util::geld((int) $p['preis_jahr_cent'])) ?> / Jahr<?php endif; ?><br>
              <?= Util::h((string) $p['beschreibung']) ?><br>
              <span class="gedimmt-2"><?= count($p['module']) ?> Zusatzmodule · Team <?= (int) $p['team_grenze'] === 0 ? 'unbegrenzt' : 'bis ' . (int) $p['team_grenze'] ?></span>
            </span>
          </label>
        <?php endforeach; ?>
      </div>
      <p class="klein gedimmt mt-3 mb-0">Nur aktive Pakete lassen sich vergeben. Pakete verwaltest du unter
        <a href="<?= Util::attr(App::url('/master/pakete.php')) ?>">Pakete</a>.</p>
    </div>
  </section>

  <section class="karte assistent__schritt" data-schritt="4" aria-labelledby="s4">
    <div class="karte__kopf"><h2 id="s4">4 · Laufzeit</h2></div>
    <div class="karte__koerper">
      <?= $feldFehler('laufzeit') ?>
      <div class="wahl-karten wahl-karten--4">
        <?php foreach (['test' => 'Testphase ' . Plattform::zahl('test_tage') . ' Tage, ohne Berechnung. Status „Testphase".',
                        'monat' => 'Monatlich zum Listenpreis des Pakets.',
                        'jahr' => 'Jährlich zum Jahrespreis des Pakets – nur, wenn einer festgelegt ist.',
                        'individuell' => 'Eigener Betrag je Monat, etwa ein Sonderpreis oder kostenlos.'] as $k => $text): ?>
          <label class="wahl-karte">
            <input type="radio" name="laufzeit" value="<?= $k ?>"<?= $e['laufzeit'] === $k ? ' checked' : '' ?>>
            <span class="wahl-karte__titel"><?= Util::h(Abos::LAUFZEIT[$k]) ?></span>
            <span class="wahl-karte__text"><?= Util::h($text) ?></span>
          </label>
        <?php endforeach; ?>
      </div>
      <div class="feld-reihe feld-reihe--3 mt-4">
        <div class="feld"><label class="feld__label" for="n-beginn">Beginn</label>
          <input class="eingabe" id="n-beginn" type="date" name="beginn" value="<?= Util::attr($e['beginn']) ?>"></div>
        <div class="feld" data-nur-laufzeit="test"><label class="feld__label" for="n-test-bis">Testphase endet am</label>
          <input class="eingabe" id="n-test-bis" type="date" name="test_bis" value="<?= Util::attr($e['test_bis']) ?>"></div>
        <div class="feld" data-nur-laufzeit="individuell"><label class="feld__label" for="n-preis">Betrag je Monat</label>
          <input class="eingabe" id="n-preis" name="preis" inputmode="decimal" placeholder="0,00"
                 value="<?= (int) $e['preis_cent'] > 0 ? Util::attr(number_format((int) $e['preis_cent'] / 100, 2, ',', '')) : '' ?>"></div>
      </div>
      <div class="feld"><label class="feld__label" for="n-notiz">Notiz zum Vertrag</label>
        <input class="eingabe" id="n-notiz" name="notiz" maxlength="500" value="<?= Util::attr($e['notiz']) ?>"
               placeholder="z. B. Sonderkonditionen, Ansprechpartner"></div>
      <p class="klein gedimmt mb-0">Vertragsdaten sind keine Rechnung: Eine Abrechnung ist noch nicht angeschlossen.</p>
    </div>
  </section>

  <section class="karte assistent__schritt" data-schritt="5" aria-labelledby="s5">
    <div class="karte__kopf"><h2 id="s5">5 · Technik</h2></div>
    <div class="karte__koerper">
      <div class="feld-reihe feld-reihe--2">
        <div class="feld"><label class="feld__label" for="n-slug">Kurzadresse *</label>
          <input class="eingabe mono<?= isset($fehler['slug']) ? ' eingabe--fehler' : '' ?>" id="n-slug" name="slug"
                 maxlength="64" pattern="[a-z0-9]([a-z0-9-]*[a-z0-9])?" value="<?= Util::attr($e['slug']) ?>"
                 placeholder="aus dem Namen" data-slug-quelle="#n-name"><?= $feldFehler('slug') ?>
          <div class="feld__hinweis">Die Website ist damit unter <span class="mono" data-slug-vorschau><?= Util::h($basis) ?>?w=…</span> erreichbar.</div></div>
        <div class="feld"><label class="feld__label" for="n-domain">Eigene Domain</label>
          <input class="eingabe mono<?= isset($fehler['domain']) ? ' eingabe--fehler' : '' ?>" id="n-domain" name="domain"
                 maxlength="160" value="<?= Util::attr($e['domain']) ?>" placeholder="golfschule-muster.de"><?= $feldFehler('domain') ?>
          <div class="feld__hinweis">Optional. Die Domain muss per DNS auf diesen Server zeigen – das richtet TeePilot nicht selbst ein.</div></div>
      </div>
      <p class="klein gedimmt mb-0">Die Instanz-ID vergibt das System beim Anlegen. Subdomains je Instanz unterstützt TeePilot derzeit nicht.</p>
    </div>
  </section>

  <section class="karte assistent__schritt" data-schritt="6" aria-labelledby="s6">
    <div class="karte__kopf"><h2 id="s6">6 · Bestätigung</h2></div>
    <div class="karte__koerper">
      <dl class="angaben" data-zusammenfassung>
        <?= angabe('Instanz', '<span data-zeige="name">' . Util::h($e['name']) . '</span>') ?>
        <?= angabe('Golfpro', '<span data-zeige="inhaber">' . Util::h($e['inhaber']) . '</span> · <span data-zeige="email">' . Util::h($e['email']) . '</span>') ?>
        <?= angabe('Paket', '<span data-zeige="paket"></span>') ?>
        <?= angabe('Laufzeit', '<span data-zeige="laufzeit"></span>') ?>
        <?= angabe('Kurzadresse', '<span class="mono" data-zeige="slug">' . Util::h($e['slug']) . '</span>') ?>
        <?= angabe('Einladung', '<span data-zeige="einladen"></span>') ?>
      </dl>
      <p class="gedimmt">Angelegt werden Instanz, Inhaber, Grundeinstellungen, Standort und Vertragsdaten – zusammen oder gar nicht.</p>
    </div>
  </section>

  <div class="assistent__knoepfe">
    <button type="button" class="btn" data-zurueck>Zurück</button>
    <div class="fueller"></div>
    <a class="btn btn--geist" href="<?= Util::attr(App::url('/master/instanzen.php')) ?>">Abbrechen</a>
    <button type="button" class="btn btn--primaer" data-weiter>Weiter</button>
    <button type="submit" class="btn btn--primaer" data-anlegen><?= Icon::svg('check', 15) ?> Instanz anlegen</button>
  </div>
</form>

<?php require __DIR__ . '/partials/fuss.php';
