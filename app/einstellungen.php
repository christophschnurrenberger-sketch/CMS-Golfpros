<?php
/** Einstellungen – alles, was einmal eingerichtet und selten geändert wird. */
require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/partials/helfer.php';

Auth::fordern('modul.settings');
Auth::fordern('settings.allgemein');

if (App::istPost()) {
    Auth::csrfFordern();
    $aktion = App::aktion();

    if ($aktion === 'allgemein') {
        Tenant::aktualisieren([
            'name' => App::post('name'),
            'domain' => strtolower(preg_replace('/^https?:\/\/|^www\.|\/$/', '', App::post('domain')) ?? ''),
            'typ' => App::post('typ', 'pro'),
            'waehrung' => App::post('waehrung', 'EUR'),
        ]);
        foreach (['website_beschreibung', 'mail_absender', 'mail_absender_name'] as $k) {
            Tenant::einstellungSetzen($k, App::post($k));
        }
        App::melden('Einstellungen gespeichert.');
    }

    if ($aktion === 'rechnung') {
        foreach (['rechnung_praefix', 'rechnung_absender', 'rechnung_fuss', 'steuernummer', 'bank',
                  'erloeskonto'] as $k) {
            Tenant::einstellungSetzen($k, App::post($k));
        }
        Tenant::einstellungSetzen('steuersatz', App::postInt('steuersatz', 19));
        Tenant::einstellungSetzen('zahlungsziel_tage', App::postInt('zahlungsziel_tage', 14));
        Tenant::einstellungSetzen('kleinunternehmer', App::postBool('kleinunternehmer'));
        App::melden('Rechnungsangaben gespeichert.');
    }

    if ($aktion === 'buchung') {
        Tenant::einstellungSetzen('stornofrist_stunden', App::postInt('stornofrist_stunden', 24));
        Tenant::einstellungSetzen('buchung_bestaetigung', App::post('buchung_bestaetigung'));
        Tenant::einstellungSetzen('registrierung_offen', App::postBool('registrierung_offen'));
        App::melden('Buchungseinstellungen gespeichert.');
    }

    if ($aktion === 'erinnerungen') {
        $vorlauf = Erinnerungen::vorlaufSaeubern(App::postListe('vorlauf'));
        $kanaele = Erinnerungen::kanaeleSaeubern(App::postListe('kanaele'));
        Tenant::einstellungSetzen('erinnerungen_aktiv', App::postBool('erinnerungen_aktiv'));
        Tenant::einstellungSetzen('erinnerung_vorlauf', $vorlauf);
        Tenant::einstellungSetzen('erinnerung_kanaele', $kanaele);

        /*
         * Die neue Vorgabe gilt sofort – auch für Termine, die schon im
         * Kalender stehen. Alles andere wäre eine Einstellung, die scheinbar
         * nichts tut: Wer „zwei Tage vorher" wählt, meint die Termine, die er
         * gerade vor sich sieht.
         */
        $geplant = Erinnerungen::vorgabeNeuPlanen();
        App::melden('Erinnerungen gespeichert.'
            . ($geplant > 0 ? ' ' . $geplant . ' Erinnerungen für kommende Termine neu geplant.' : ''));
    }
    App::weiter('/app/einstellungen.php');
}

$w = Tenant::workspace();
$titel = 'Einstellungen';
$unter = 'Workspace, Rechnungsangaben, Buchung, Erinnerungen und Zahlungen.';
require __DIR__ . '/partials/kopf.php';
?>

<div class="raster raster--4 mb-5">
  <?php foreach ([
    ['Team', count(Auth::trainer()) . ' Personen', 'customers', '/app/team.php', 'settings.team'],
    ['Standorte', Tenant::count('locations') . ' Orte', 'pin', '/app/standorte.php', 'settings.allgemein'],
    ['Tarif', Module::planName(Tenant::plan()), 'layers', '/app/tarif.php', 'settings.allgemein'],
    ['Datenschutz', 'DSGVO-Werkzeuge', 'lock', '/app/datenschutz.php', 'settings.recht'],
  ] as [$name, $text, $icon, $url, $recht]):
    if (!Auth::darf($recht)) { continue; } ?>
    <a class="kachel-link" href="<?= Util::attr(App::url($url)) ?>">
      <span class="kachel-link__symbol"><?= Icon::svg($icon, 17) ?></span>
      <div style="flex:1;min-width:0">
        <div class="kachel-link__titel"><?= Util::h($name) ?></div>
        <div class="kachel-link__text"><?= Util::h($text) ?></div>
      </div>
      <?= Icon::svg('chevron-right', 15) ?>
    </a>
  <?php endforeach; ?>
</div>

<div class="reiter" data-reiter-gruppe="e">
  <button class="reiter__teil ist-aktiv" data-reiter="allgemein">Allgemein</button>
  <button class="reiter__teil" data-reiter="rechnung">Rechnungen</button>
  <button class="reiter__teil" data-reiter="buchung">Buchung</button>
  <button class="reiter__teil" data-reiter="erinnerungen">Erinnerungen</button>
  <button class="reiter__teil" data-reiter="zahlungen">Zahlungen &amp; KI</button>
</div>

<div data-reiter-feld="allgemein" data-reiter-gruppe="e">
  <form method="post" class="karte" style="max-width:760px">
    <?= Auth::csrfFeld() ?>
    <input type="hidden" name="aktion" value="allgemein">
    <div class="karte__koerper">
      <div class="feld-reihe feld-reihe--2">
        <div class="feld"><label class="feld__label" for="name">Name</label>
          <input class="eingabe" id="name" name="name" value="<?= Util::attr((string) $w['name']) ?>">
          <div class="feld__hinweis">Erscheint auf Website, Rechnungen und in E-Mails.</div></div>
        <div class="feld"><label class="feld__label" for="typ">Art des Betriebs</label>
          <select id="typ" name="typ">
            <option value="pro"<?= (string) $w['typ'] === 'pro' ? ' selected' : '' ?>>Einzelner Golf Professional</option>
            <option value="academy"<?= (string) $w['typ'] === 'academy' ? ' selected' : '' ?>>Golf Academy (mehrere Trainer)</option>
          </select></div>
      </div>
      <div class="feld"><label class="feld__label" for="domain">Eigene Domain</label>
        <div class="eingabe-gruppe">
          <span class="eingabe-gruppe__anhang eingabe-gruppe__anhang--vorn">https://</span>
          <input class="eingabe" id="domain" name="domain" value="<?= Util::attr((string) $w['domain']) ?>"
                 placeholder="golfschule-mustermann.de">
        </div>
        <div class="feld__hinweis">Die Domain beim Anbieter auf diesen Server zeigen lassen. Sobald sie
          hier steht, liefert das System darunter die Website dieses Workspace aus.</div></div>
      <div class="feld"><label class="feld__label" for="website_beschreibung">Kurzbeschreibung</label>
        <textarea class="eingabe" id="website_beschreibung" name="website_beschreibung" rows="2" data-waechst
          placeholder="Golftraining für Einsteiger und Fortgeschrittene in …"><?= Util::h((string) Tenant::einstellung('website_beschreibung', '')) ?></textarea>
        <div class="feld__hinweis">Für Suchmaschinen und strukturierte Daten.</div></div>
      <div class="feld-reihe feld-reihe--3">
        <div class="feld"><label class="feld__label" for="waehrung">Währung</label>
          <select id="waehrung" name="waehrung">
            <?php foreach (['EUR' => 'Euro (€)', 'CHF' => 'Schweizer Franken', 'GBP' => 'Britisches Pfund'] as $k => $v): ?>
              <option value="<?= $k ?>"<?= (string) $w['waehrung'] === $k ? ' selected' : '' ?>><?= $v ?></option>
            <?php endforeach; ?>
          </select></div>
        <div class="feld"><label class="feld__label" for="mail_absender_name">Absendername</label>
          <input class="eingabe" id="mail_absender_name" name="mail_absender_name"
                 value="<?= Util::attr((string) Tenant::einstellung('mail_absender_name', (string) $w['name'])) ?>"></div>
        <div class="feld"><label class="feld__label" for="mail_absender">Absenderadresse</label>
          <input class="eingabe" id="mail_absender" type="email" name="mail_absender"
                 value="<?= Util::attr((string) Tenant::einstellung('mail_absender', '')) ?>"></div>
      </div>
    </div>
    <div class="karte__fuss"><div class="fueller"></div>
      <button class="btn btn--primaer" type="submit">Speichern</button></div>
  </form>
</div>

<div data-reiter-feld="rechnung" data-reiter-gruppe="e" class="versteckt">
  <form method="post" class="karte" style="max-width:760px">
    <?= Auth::csrfFeld() ?>
    <input type="hidden" name="aktion" value="rechnung">
    <div class="karte__koerper">
      <div class="feld"><label class="feld__label" for="rechnung_absender">Absenderzeile</label>
        <input class="eingabe" id="rechnung_absender" name="rechnung_absender"
               value="<?= Util::attr((string) Tenant::einstellung('rechnung_absender', '')) ?>"
               placeholder="Golfschule Mustermann · Musterweg 1 · 12345 Musterstadt"></div>
      <div class="feld-reihe feld-reihe--3">
        <div class="feld"><label class="feld__label" for="rechnung_praefix">Nummernkreis</label>
          <input class="eingabe" id="rechnung_praefix" name="rechnung_praefix" maxlength="6"
                 value="<?= Util::attr((string) Tenant::einstellung('rechnung_praefix', 'R')) ?>">
          <div class="feld__hinweis">Ergibt <?= Util::h((string) Tenant::einstellung('rechnung_praefix', 'R')) ?>-<?= date('Y') ?>-0001</div></div>
        <div class="feld"><label class="feld__label" for="steuersatz">Steuersatz</label>
          <select id="steuersatz" name="steuersatz">
            <?php foreach ([19 => '19 % (Regelsatz)', 7 => '7 % (ermäßigt)', 0 => '0 %'] as $k => $v): ?>
              <option value="<?= $k ?>"<?= (int) Tenant::einstellung('steuersatz', 19) === $k ? ' selected' : '' ?>><?= $v ?></option>
            <?php endforeach; ?>
          </select></div>
        <div class="feld"><label class="feld__label" for="zahlungsziel_tage">Zahlungsziel (Tage)</label>
          <input class="eingabe" id="zahlungsziel_tage" type="number" name="zahlungsziel_tage" min="0"
                 value="<?= (int) Tenant::einstellung('zahlungsziel_tage', 14) ?>"></div>
      </div>
      <div class="feld-reihe feld-reihe--2">
        <div class="feld"><label class="feld__label" for="steuernummer">Steuernummer / USt-IdNr.</label>
          <input class="eingabe" id="steuernummer" name="steuernummer"
                 value="<?= Util::attr((string) Tenant::einstellung('steuernummer', '')) ?>"></div>
        <div class="feld"><label class="feld__label" for="erloeskonto">Erlöskonto (Buchhaltung)</label>
          <input class="eingabe" id="erloeskonto" name="erloeskonto"
                 value="<?= Util::attr((string) Tenant::einstellung('erloeskonto', '8400')) ?>">
          <div class="feld__hinweis">Steht im CSV-Export für DATEV, lexoffice und sevDesk.</div></div>
      </div>
      <div class="feld"><label class="feld__label" for="bank">Bankverbindung</label>
        <input class="eingabe" id="bank" name="bank"
               value="<?= Util::attr((string) Tenant::einstellung('bank', '')) ?>"
               placeholder="Musterbank · IBAN DE00 0000 0000 0000 0000 00"></div>
      <div class="feld"><label class="feld__label" for="rechnung_fuss">Fußzeile der Rechnung</label>
        <input class="eingabe" id="rechnung_fuss" name="rechnung_fuss"
               value="<?= Util::attr((string) Tenant::einstellung('rechnung_fuss', '')) ?>"></div>
      <label class="haken">
        <input type="checkbox" name="kleinunternehmer" value="1"
               <?= Tenant::einstellung('kleinunternehmer', false) ? ' checked' : '' ?>>
        <span class="haken__text">Kleinunternehmerregelung (§ 19 UStG)
          <span class="haken__hinweis">Auf der Rechnung erscheint der vorgeschriebene Hinweis,
            und es wird keine Umsatzsteuer ausgewiesen.</span></span>
      </label>
      <div class="hinweis hinweis--still mt-4">
        <?= Icon::svg('info', 17) ?>
        <div class="hinweis__text">Diese Angaben sind Vorgaben für die Belegerstellung, keine
          steuerliche Beratung. Im Zweifel mit dem Steuerbüro abstimmen.</div>
      </div>
    </div>
    <div class="karte__fuss"><div class="fueller"></div>
      <button class="btn btn--primaer" type="submit">Speichern</button></div>
  </form>
</div>

<div data-reiter-feld="buchung" data-reiter-gruppe="e" class="versteckt">
  <form method="post" class="karte" style="max-width:760px">
    <?= Auth::csrfFeld() ?>
    <input type="hidden" name="aktion" value="buchung">
    <div class="karte__koerper">
      <div class="feld"><label class="feld__label" for="stornofrist_stunden">Stornofrist (Stunden)</label>
        <input class="eingabe" id="stornofrist_stunden" type="number" name="stornofrist_stunden" min="0"
               value="<?= (int) Tenant::einstellung('stornofrist_stunden', 24) ?>" style="max-width:160px">
        <div class="feld__hinweis">Bis dahin kann der Kunde kostenfrei absagen. 24 Stunden sind üblich und fair.</div></div>
      <div class="feld">
        <span class="feld__label">Kundenzugang</span>
        <label class="haken">
          <input type="checkbox" name="registrierung_offen" value="1"
                 <?= Tenant::einstellung('registrierung_offen', true) ? ' checked' : '' ?>>
          <span class="haken__text">Kunden dürfen sich selbst ein Konto anlegen
            <span class="haken__hinweis">Über <code>/portal/registrieren.php</code>, verlinkt aus dem
              Kundenzugang. Abgeschaltet legst nur du Kunden an – buchen kann trotzdem jeder,
              dafür braucht es kein Konto.</span></span>
        </label>
      </div>
      <div class="feld"><label class="feld__label" for="buchung_bestaetigung">Text der Buchungsbestätigung</label>
        <textarea class="eingabe" id="buchung_bestaetigung" name="buchung_bestaetigung" rows="4" data-waechst
          placeholder="Freut mich, bis dann! Bring bitte bequeme Schuhe mit."><?= Util::h((string) Tenant::einstellung('buchung_bestaetigung', '')) ?></textarea>
        <div class="feld__hinweis">Steht zusätzlich in jeder Bestätigungsmail.</div></div>
      <div class="hinweis hinweis--still">
        <?= Icon::svg('clock', 17) ?>
        <div class="hinweis__text">Wann und auf welchem Weg an einen Termin erinnert wird,
          steht im Reiter <em>Erinnerungen</em>.</div>
      </div>
    </div>
    <div class="karte__fuss"><div class="fueller"></div>
      <button class="btn btn--primaer" type="submit">Speichern</button></div>
  </form>
</div>

<div data-reiter-feld="erinnerungen" data-reiter-gruppe="e" class="versteckt" id="erinnerungen">
  <?php
    $vorgabeVorlauf  = Erinnerungen::vorgabeVorlauf();
    $vorgabeKanaele  = Erinnerungen::vorgabeKanaele();
    $offen = Tenant::count('reminders', "status = 'geplant'");
  ?>
  <div class="raster raster--2" style="align-items:start">
    <form method="post" class="karte">
      <?= Auth::csrfFeld() ?>
      <input type="hidden" name="aktion" value="erinnerungen">
      <div class="karte__kopf"><h3>Terminerinnerungen</h3>
        <div class="fueller"></div>
        <?= pille($offen . ' geplant') ?>
      </div>
      <div class="karte__koerper">
        <label class="haken mb-4">
          <input type="checkbox" name="erinnerungen_aktiv" value="1"
                 <?= Erinnerungen::aktiv() ? ' checked' : '' ?>>
          <span class="haken__text">Kunden automatisch an ihre Termine erinnern
            <span class="haken__hinweis">Senkt Nichterscheinen erfahrungsgemäß deutlich.</span></span>
        </label>

        <div class="feld">
          <span class="feld__label">Wann</span>
          <?php foreach (Erinnerungen::VORLAUF as $minuten => $name): ?>
            <label class="haken mb-2">
              <input type="checkbox" name="vorlauf[]" value="<?= (int) $minuten ?>"
                     <?= in_array($minuten, $vorgabeVorlauf, true) ? ' checked' : '' ?>>
              <span class="haken__text"><?= Util::h($name) ?></span>
            </label>
          <?php endforeach; ?>
          <div class="feld__hinweis">Mehrere sind möglich – dann kommt je Zeitpunkt eine Nachricht.
            Ein Vorlauf, der beim Buchen schon abgelaufen ist, entfällt für diesen Termin.</div>
        </div>

        <div class="feld">
          <span class="feld__label">Auf welchem Weg</span>
          <?php foreach (Kanaele::LISTE as $kanal => [$name, $icon]):
            [$zustand, $satz] = Kanaele::zustand($kanal); ?>
            <label class="haken mb-2">
              <input type="checkbox" name="kanaele[]" value="<?= Util::attr($kanal) ?>"
                     <?= in_array($kanal, $vorgabeKanaele, true) ? ' checked' : '' ?>>
              <span class="haken__text"><?= Util::h($name) ?>
                <?php if ($zustand !== 'bereit'): ?>
                  <span class="haken__hinweis">Noch nicht eingerichtet – ankreuzen darfst du es
                    trotzdem: Sobald die Zugangsdaten stehen, geht es von allein los.</span>
                <?php endif; ?></span>
            </label>
          <?php endforeach; ?>
        </div>
      </div>
      <div class="karte__fuss"><div class="fueller"></div>
        <button class="btn btn--primaer" type="submit">Speichern</button></div>
    </form>

    <div class="stapel">
      <div class="karte">
        <div class="karte__kopf"><h3>Kanäle</h3></div>
        <div class="karte__koerper karte__koerper--eng">
          <div class="stapel stapel--eng">
            <?php foreach (Kanaele::LISTE as $kanal => [$name, $icon]):
              [$zustand, $satz] = Kanaele::zustand($kanal); ?>
              <div style="padding:8px 4px">
                <div class="reihe">
                  <?= Icon::svg($icon, 16) ?>
                  <span class="halbfett"><?= Util::h($name) ?></span>
                  <div class="fueller"></div>
                  <?= pille($zustand === 'bereit' ? 'bereit' : 'fehlt',
                        $zustand === 'bereit' ? 'erfolg' : 'warnung') ?>
                </div>
                <div class="klein gedimmt mt-2"><?= Util::h($satz) ?></div>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      </div>

      <div class="karte">
        <div class="karte__kopf"><h3>SMS einrichten</h3></div>
        <div class="karte__koerper">
          <p class="klein">In der <code>config.php</code> unter <code>sms</code>:</p>
          <ol class="klein gedimmt" style="line-height:1.9;padding-left:1.2em">
            <li>Konto bei <em>seven.io</em> oder <em>Twilio</em> anlegen</li>
            <li><code>anbieter</code> auf <code>seven</code> oder <code>twilio</code> setzen</li>
            <li><code>api_key</code> eintragen (bei Twilio zusätzlich <code>konto</code>)</li>
            <li><code>absender</code> ist der Name, der beim Kunden steht – höchstens 11 Zeichen</li>
          </ol>
          <div class="hinweis hinweis--still mt-4">
            <?= Icon::svg('info', 16) ?>
            <div class="hinweis__text klein">Eine SMS braucht eine Nummer mit Vorwahl.
              <code>0170 1234567</code> genügt, <code>1234567</code> nicht – daraus lässt sich
              kein Land ableiten, und geraten wird hier nicht.</div>
          </div>
        </div>
      </div>

      <div class="karte">
        <div class="karte__kopf"><h3>WhatsApp</h3>
          <div class="fueller"></div><?= pille('vorbereitet') ?></div>
        <div class="karte__koerper">
          <p class="klein">Die Anbindung an die WhatsApp Business Cloud API steht. Was fehlt, ist
            der Zugang – und der ist bei Meta etwas Arbeit:</p>
          <ol class="klein gedimmt" style="line-height:1.9;padding-left:1.2em">
            <li>Meta-Business-Konto und WhatsApp Business Account anlegen</li>
            <li>Eine Rufnummer verifizieren lassen und deren <em>Phone Number ID</em> notieren</li>
            <li>Eine Nachrichtenvorlage („Terminerinnerung") einreichen und freigeben lassen</li>
            <li>Beides mit einem dauerhaften Token in der <code>config.php</code> unter
              <code>whatsapp</code> eintragen</li>
          </ol>
          <div class="hinweis hinweis--still mt-4">
            <?= Icon::svg('info', 16) ?>
            <div class="hinweis__text klein">Warum eine Vorlage: Meta lässt außerhalb eines
              laufenden Gesprächs keine frei formulierten Nachrichten zu. Die Vorlage bekommt
              Vorname, Leistung, Datum, Uhrzeit und den Namen der Schule als Platzhalter.</div>
          </div>
        </div>
      </div>

      <div class="hinweis hinweis--still">
        <?= Icon::svg('clock', 17) ?>
        <div class="hinweis__text">Versendet wird beim Öffnen des Dashboards, höchstens alle
          15 Minuten. Wer einen Cronjob einrichten kann, ruft in diesem Takt
          <code>cron.php</code> auf – dann kommen die Erinnerungen pünktlich, auch wenn niemand
          im Backend arbeitet.</div>
      </div>
    </div>
  </div>
</div>

<div data-reiter-feld="zahlungen" data-reiter-gruppe="e" class="versteckt" id="zahlungen">
  <div class="raster raster--2" style="align-items:start">
    <div class="karte">
      <div class="karte__kopf"><h3>Stripe</h3>
        <div class="fueller"></div>
        <?= pille(Stripe::eingerichtet() ? (Stripe::testmodus() ? 'Testschlüssel' : 'eingerichtet') : 'Testmodus',
              Stripe::eingerichtet() && !Stripe::testmodus() ? 'erfolg' : 'warnung') ?>
      </div>
      <div class="karte__koerper">
        <?php if (Stripe::eingerichtet()): ?>
          <p class="klein">Zahlungen laufen über Stripe. Unterstützt sind Kreditkarte,
            SEPA-Lastschrift, Apple Pay und Google Pay.</p>
        <?php else: ?>
          <p class="klein">Ohne Schlüssel läuft alles im Testmodus: Bestellungen werden angelegt
            und als bezahlt verbucht, es fließt aber kein Geld. Für echte Zahlungen:</p>
          <ol class="klein gedimmt" style="line-height:1.9;padding-left:1.2em">
            <li>Konto bei stripe.com anlegen</li>
            <li>Unter <em>Entwickler → API-Schlüssel</em> den geheimen Schlüssel kopieren</li>
            <li>In der <code>config.php</code> bei <code>stripe.secret_key</code> eintragen</li>
            <li>Webhook auf <code><?= Util::h(App::absolut('/webhook.php')) ?></code> einrichten</li>
          </ol>
        <?php endif; ?>
        <div class="hinweis hinweis--still mt-4">
          <?= Icon::svg('shield', 16) ?>
          <div class="hinweis__text klein">Kartendaten erreichen diesen Server nie – bezahlt wird
            auf einer Seite von Stripe. Damit bleiben die PCI-Pflichten dort.</div>
        </div>
      </div>
    </div>

    <div class="karte">
      <div class="karte__kopf"><h3>KI-Zugang</h3>
        <div class="fueller"></div>
        <?= pille(KI::verfuegbar() ? 'eingerichtet' : 'regelbasiert', KI::verfuegbar() ? 'erfolg' : '') ?>
      </div>
      <div class="karte__koerper">
        <?php if (KI::verfuegbar()): ?>
          <p class="klein">Ein Sprachmodell formuliert Texte und beantwortet freie Fragen.
            Zahlen kommen weiterhin aus der Datenbank.</p>
        <?php else: ?>
          <p class="klein">Ohne Schlüssel arbeiten alle KI-Funktionen regelbasiert weiter:
            Website- und Trainingsplanentwürfe aus einer gepflegten Textbasis, Fragen zu Zahlen
            aus der Datenbank. Für frei formulierte Antworten trägst du in der
            <code>config.php</code> unter <code>ai.api_key</code> einen Schlüssel ein.</p>
        <?php endif; ?>
        <div class="hinweis hinweis--still mt-4">
          <?= Icon::svg('info', 16) ?>
          <div class="hinweis__text klein">Die KI führt nie selbst etwas aus. Preise, Rechnungen,
            Versand und Löschungen bestätigt immer ein Mensch.</div>
        </div>
      </div>
    </div>
  </div>
</div>

<?php require __DIR__ . '/partials/fuss.php'; ?>
