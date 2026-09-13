<?php
/**
 * Datenschutz – Einwilligungen, Auskunft, Löschung, Aufbewahrung.
 *
 * Diese Seite ersetzt keine Rechtsberatung und behauptet das auch
 * nirgends. Sie tut das, was Software leisten kann: die Vorgänge
 * nachvollziehbar machen, Fristen einhalten und die Auskunfts- und
 * Löschanfragen an einem Ort sammeln, statt sie im Postfach zu verlieren.
 */
require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/partials/helfer.php';

Auth::fordern('modul.settings');
Auth::fordern('settings.recht');

if (App::istPost()) {
    Auth::csrfFordern();
    $aktion = App::aktion();

    if ($aktion === 'aufbewahrung') {
        Tenant::einstellungSetzen('aufbewahrung_protokoll', max(30, App::postInt('aufbewahrung_protokoll', 365)));
        Tenant::einstellungSetzen('aufbewahrung_besuche', max(30, App::postInt('aufbewahrung_besuche', 400)));
        Tenant::einstellungSetzen('aufbewahrung_leads', max(0, App::postInt('aufbewahrung_leads', 730)));
        Tenant::einstellungSetzen('cookie_banner', App::postBool('cookie_banner'));
        Tenant::einstellungSetzen('analyse_aktiv', App::postBool('analyse_aktiv'));
        Tenant::einstellungSetzen('auftragsverarbeiter', App::post('auftragsverarbeiter'));
        App::melden('Einstellungen gespeichert.');
    }

    if ($aktion === 'anfrage_erledigt') {
        $id = App::postInt('id');
        $a  = Tenant::find('data_requests', $id);
        if ($a !== null) {
            Tenant::update('data_requests', $id, [
                'status'   => 'erledigt',
                'erledigt' => Util::jetzt(),
                'notiz'    => App::post('notiz', (string) $a['notiz']),
            ]);
            Audit::schreiben('geaendert', 'data_request', $id, 'erledigt');
            App::melden('Anfrage als erledigt vermerkt.');
        }
    }

    if ($aktion === 'wartung') {
        $ergebnis = Wartung::aufraeumen();
        App::melden($ergebnis === 0
            ? 'Nichts zu löschen – alle Daten liegen innerhalb der Fristen.'
            : $ergebnis . ' Datensätze außerhalb der Aufbewahrungsfrist gelöscht.');
    }

    App::weiter('/app/datenschutz.php');
}

/* ---------------------------------------------------------- Export --- */

if (App::get('export') === 'kunde') {
    $kundeId = App::getInt('id');
    $daten   = Customers::export($kundeId);
    if ($daten === []) {
        App::melden('Kunde nicht gefunden.', 'fehler');
        App::weiter('/app/datenschutz.php');
    }
    Audit::schreiben('export', 'customer', $kundeId, 'Datenauskunft nach Artikel 20 DSGVO');
    $name = Util::slug(Customers::nameVonId($kundeId)) ?: 'kunde-' . $kundeId;
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="auskunft-' . $name . '.json"');
    echo Util::json($daten);
    exit;
}

$offene  = Tenant::all('data_requests', 'status != "erledigt"', [], 'erstellt');
$erledigt = Tenant::all('data_requests', 'status = "erledigt"', [], 'erledigt DESC', 10);
$einwilligungen = Tenant::all('consents', '', [], 'id DESC', 25);
$anzahlEinw = Tenant::count('consents');
$newsletter = Tenant::count('customers', 'newsletter = 1');
$kunden     = Tenant::count('customers');
$rechtsseiten = Tenant::all('pages', 'slug IN ("impressum","datenschutz","agb")', [], 'slug');
$vorhanden  = array_column($rechtsseiten, 'slug');

$titel = 'Datenschutz';
$unter = 'Einwilligungen, Auskunft, Löschung und Aufbewahrungsfristen.';
$brotkrumen = [['Einstellungen', '/app/einstellungen.php'], ['Datenschutz', null]];
require __DIR__ . '/partials/kopf.php';
?>

<div class="raster raster--4 mb-5">
  <?= kennzahl('Einwilligungen protokolliert', Util::zahl($anzahlEinw), ['icon' => 'check',
        'fuss' => 'jede mit Zeitpunkt und Quelle']) ?>
  <?= kennzahl('Newsletter-Zustimmung', Util::zahl($newsletter),
        ['icon' => 'newsletter', 'fuss' => 'von ' . Util::zahl($kunden) . ' Kunden']) ?>
  <?= kennzahl('Offene Anfragen', Util::zahl(count($offene)), ['icon' => 'inbox',
        'fuss' => count($offene) > 0 ? 'binnen eines Monats zu beantworten' : 'alles erledigt']) ?>
  <?= kennzahl('Rechtsseiten', count($vorhanden) . ' von 3', ['icon' => 'lock',
        'fuss' => 'Impressum, Datenschutz, AGB']) ?>
</div>

<?php if (count($vorhanden) < 3): ?>
  <div class="hinweis hinweis--warnung mb-4">
    <?= Icon::svg('alert', 17) ?>
    <div class="hinweis__text">
      <span class="hinweis__titel">Es fehlen Pflichtseiten</span>
      Auf der Website fehlen: <?= Util::h(implode(', ', array_diff(['impressum', 'datenschutz', 'agb'], $vorhanden))) ?>.
      <a href="<?= Util::attr(App::url('/app/website.php')) ?>">Im Website-Bereich anlegen</a> – es gibt
      dort Vorlagen zum Ausfüllen.
    </div>
  </div>
<?php endif; ?>

<div class="reiter" data-reiter-gruppe="d">
  <button class="reiter__teil ist-aktiv" data-reiter="anfragen">Anfragen<?= count($offene) > 0 ? ' (' . count($offene) . ')' : '' ?></button>
  <button class="reiter__teil" data-reiter="einwilligungen">Einwilligungen</button>
  <button class="reiter__teil" data-reiter="auskunft">Auskunft &amp; Löschung</button>
  <button class="reiter__teil" data-reiter="fristen">Fristen &amp; Zählung</button>
</div>

<div data-reiter-feld="anfragen" data-reiter-gruppe="d">
  <div class="karte mb-4">
    <div class="karte__kopf"><h2>Offene Anfragen</h2></div>
    <?php if ($offene === []): ?>
      <div class="karte__koerper">
        <?= leerzustand('check', 'Keine offenen Anfragen',
              'Auskunfts- und Löschanfragen aus dem Kundenportal landen hier. Die Frist beträgt '
              . 'einen Monat ab Eingang.') ?>
      </div>
    <?php else: ?>
      <div class="tabelle-huelle">
        <table class="tabelle">
          <thead><tr><th>Eingang</th><th>Kunde</th><th>Art</th><th>Frist</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($offene as $a):
            $tage = 30 - Util::tageSeit((string) $a['erstellt']); ?>
            <tr>
              <td class="klein gedimmt"><?= Util::h(Util::datum((string) $a['erstellt'])) ?></td>
              <td>
                <?php if ((int) $a['customer_id'] > 0): ?>
                  <a href="<?= Util::attr(App::url('/app/kunde.php?id=' . (int) $a['customer_id'])) ?>">
                    <?= Util::h(Customers::nameVonId((int) $a['customer_id'])) ?></a>
                <?php else: ?>
                  <span class="gedimmt">unbekannt</span>
                <?php endif; ?>
              </td>
              <td><?= pille((string) $a['typ'] === 'loeschung' ? 'Löschung' : 'Auskunft',
                    (string) $a['typ'] === 'loeschung' ? 'gefahr' : 'info') ?></td>
              <td><?= $tage < 0
                    ? pille('überfällig', 'gefahr')
                    : pille('noch ' . $tage . ' Tage', $tage <= 7 ? 'warnung' : '') ?></td>
              <td class="rechts">
                <div class="reihe reihe--eng reihe--rechts">
                  <?php if ((int) $a['customer_id'] > 0 && (string) $a['typ'] !== 'loeschung'): ?>
                    <a class="btn btn--klein"
                       href="<?= Util::attr(App::url('/app/datenschutz.php?export=kunde&id=' . (int) $a['customer_id'])) ?>">
                      <?= Icon::svg('download', 14) ?> Daten holen</a>
                  <?php endif; ?>
                  <form method="post" class="inline">
                    <?= Auth::csrfFeld() ?>
                    <input type="hidden" name="aktion" value="anfrage_erledigt">
                    <input type="hidden" name="id" value="<?= (int) $a['id'] ?>">
                    <button class="btn btn--klein btn--primaer" type="submit">Erledigt</button>
                  </form>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

  <?php if ($erledigt !== []): ?>
    <?= karteAuf('Zuletzt erledigt') ?>
      <div class="tabelle-huelle">
        <table class="tabelle tabelle--eng">
          <thead><tr><th>Erledigt am</th><th>Kunde</th><th>Art</th><th class="nicht-mobil">Notiz</th></tr></thead>
          <tbody>
          <?php foreach ($erledigt as $a): ?>
            <tr>
              <td class="klein gedimmt"><?= Util::h(Util::datum((string) $a['erledigt'])) ?></td>
              <td class="klein"><?= (int) $a['customer_id'] > 0
                    ? Util::h(Customers::nameVonId((int) $a['customer_id'])) : '—' ?></td>
              <td class="klein"><?= Util::h((string) $a['typ'] === 'loeschung' ? 'Löschung' : 'Auskunft') ?></td>
              <td class="nicht-mobil klein gedimmt"><?= Util::h(Util::kuerzen((string) $a['notiz'], 70)) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  <?php endif; ?>
</div>

<div data-reiter-feld="einwilligungen" data-reiter-gruppe="d" class="versteckt">
  <?= karteAuf('Einwilligungsprotokoll',
        '<span class="klein gedimmt">' . Util::zahl($anzahlEinw) . ' Einträge insgesamt</span>') ?>
    <?php if ($einwilligungen === []): ?>
      <div class="karte__koerper">
        <?= leerzustand('check', 'Noch keine Einwilligungen protokolliert',
              'Sobald jemand über ein Formular, die Buchung oder den Newsletter zustimmt, wird der '
              . 'Wortlaut mit Zeitpunkt und Quelle festgehalten – das ist der Nachweis, den Artikel 7 '
              . 'Absatz 1 DSGVO verlangt.') ?>
      </div>
    <?php else: ?>
      <div class="tabelle-huelle">
        <table class="tabelle tabelle--eng">
          <thead><tr>
            <th>Zeitpunkt</th><th>Person</th><th>Zweck</th><th>Status</th>
            <th class="nicht-mobil">Quelle</th><th class="nicht-mobil">Wortlaut</th>
          </tr></thead>
          <tbody>
          <?php foreach ($einwilligungen as $e): ?>
            <tr>
              <td class="klein gedimmt umbruch-nein"><?= Util::h(Util::datumZeit((string) $e['erstellt'])) ?></td>
              <td class="klein">
                <?php if ((int) $e['customer_id'] > 0): ?>
                  <?= Util::h(Customers::nameVonId((int) $e['customer_id'])) ?>
                <?php elseif ((int) $e['lead_id'] > 0): ?>
                  <span class="gedimmt">Lead #<?= (int) $e['lead_id'] ?></span>
                <?php else: ?><span class="gedimmt">—</span><?php endif; ?>
              </td>
              <td class="klein"><?= Util::h((string) $e['typ']) ?></td>
              <td><?= (int) $e['erteilt'] === 1 ? pille('erteilt', 'erfolg') : pille('widerrufen', 'gefahr') ?></td>
              <td class="nicht-mobil klein gedimmt"><?= Util::h((string) $e['quelle']) ?></td>
              <td class="nicht-mobil klein gedimmt"><?= Util::h(Util::kuerzen((string) $e['text'], 60)) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>

<div data-reiter-feld="auskunft" data-reiter-gruppe="d" class="versteckt">
  <div class="raster raster--2">
    <?= karteAuf('Datenauskunft erteilen') ?>
      <div class="karte__koerper">
        <p class="gedimmt mb-4">Artikel 15 und 20 DSGVO: Auf Verlangen bekommt eine Person alle zu
          ihr gespeicherten Daten in einem maschinenlesbaren Format. Der Export enthält Stammdaten,
          Termine, Pakete, Rechnungen, Trainingspläne, Leistungsdaten und Einwilligungen –
          ohne Passwörter und ohne interne Zugangstoken.</p>
        <div class="feld">
          <label class="feld__label" for="ds-kunde">Kunde auswählen</label>
          <div class="reihe reihe--eng">
            <select id="ds-kunde" class="eingabe">
              <option value="0">– bitte wählen –</option>
              <?php foreach (Tenant::all('customers', '', [], 'nachname, vorname', 500) as $k): ?>
                <option value="<?= (int) $k['id'] ?>"><?= Util::h(Customers::name($k)) ?></option>
              <?php endforeach; ?>
            </select>
            <a class="btn btn--primaer" id="ds-export"
               href="<?= Util::attr(App::url('/app/datenschutz.php?export=kunde&id=0')) ?>">
              <?= Icon::svg('download', 15) ?> Auskunft laden</a>
          </div>
          <div class="feld__hinweis">Erzeugt eine JSON-Datei. Jeder Export wird im
            <a href="<?= Util::attr(App::url('/app/protokoll.php')) ?>">Protokoll</a> vermerkt.</div>
        </div>
      </div>
    </div>

    <?= karteAuf('Daten löschen') ?>
      <div class="karte__koerper">
        <p class="gedimmt mb-4">Artikel 17 DSGVO: Die Akte wird vollständig entfernt –
          Termine, Notizen, Videos, Trainingspläne, Leistungsdaten und Einwilligungen.</p>
        <div class="hinweis hinweis--warnung">
          <?= Icon::svg('info', 17) ?>
          <div class="hinweis__text">
            <span class="hinweis__titel">Bezahlte Rechnungen bleiben</span>
            Handels- und steuerrechtliche Aufbewahrungspflichten gehen dem Löschrecht hier vor
            (§ 147 AO, § 257 HGB). Der Beleg bleibt gültig, der Bezug zur Kundenakte wird aber
            gekappt: In der Rechnung steht dann nur noch der eingefrorene Name.
          </div>
        </div>
        <p class="klein gedimmt mt-4">Die Löschung startet in der Kundenakte unter
          „Weitere Aktionen“ – bewusst dort, wo man den Datensatz vor Augen hat, und nicht
          als Dropdown in den Einstellungen.</p>
        <a class="btn mt-3" href="<?= Util::attr(App::url('/app/kunden.php')) ?>">
          <?= Icon::svg('customers', 15) ?> Zu den Kunden</a>
      </div>
    </div>
  </div>
</div>

<div data-reiter-feld="fristen" data-reiter-gruppe="d" class="versteckt">
  <form method="post" class="karte" style="max-width:760px">
    <?= Auth::csrfFeld() ?>
    <input type="hidden" name="aktion" value="aufbewahrung">
    <div class="karte__koerper">
      <p class="gedimmt mb-4">Daten, die niemand mehr braucht, sollen verschwinden. Die Wartung
        räumt nach diesen Fristen selbsttätig auf – auch ohne Cronjob, spätestens beim nächsten
        Aufruf des Dashboards.</p>
      <div class="feld-reihe feld-reihe--3">
        <div class="feld"><label class="feld__label" for="aufbewahrung_protokoll">Änderungsprotokoll</label>
          <div class="eingabe-gruppe">
            <input class="eingabe" id="aufbewahrung_protokoll" type="number" name="aufbewahrung_protokoll" min="30"
                   value="<?= (int) Tenant::einstellung('aufbewahrung_protokoll', 365) ?>">
            <span class="eingabe-gruppe__anhang">Tage</span>
          </div></div>
        <div class="feld"><label class="feld__label" for="aufbewahrung_besuche">Websitezahlen</label>
          <div class="eingabe-gruppe">
            <input class="eingabe" id="aufbewahrung_besuche" type="number" name="aufbewahrung_besuche" min="30"
                   value="<?= (int) Tenant::einstellung('aufbewahrung_besuche', 400) ?>">
            <span class="eingabe-gruppe__anhang">Tage</span>
          </div>
          <div class="feld__hinweis">400 Tage erlauben den Vorjahresvergleich.</div></div>
        <div class="feld"><label class="feld__label" for="aufbewahrung_leads">Verlorene Leads</label>
          <div class="eingabe-gruppe">
            <input class="eingabe" id="aufbewahrung_leads" type="number" name="aufbewahrung_leads" min="0"
                   value="<?= (int) Tenant::einstellung('aufbewahrung_leads', 730) ?>">
            <span class="eingabe-gruppe__anhang">Tage</span>
          </div>
          <div class="feld__hinweis">0 heißt: nie automatisch löschen.</div></div>
      </div>

      <div class="feld mt-4">
        <span class="feld__label">Zählung der Websitebesuche</span>
        <label class="haken mb-3">
          <input type="checkbox" name="analyse_aktiv" value="1"
                 <?= Tenant::einstellung('analyse_aktiv', true) ? ' checked' : '' ?>>
          <span class="haken__text">Besuche zählen
            <span class="haken__hinweis">Ohne Cookies und ohne gespeicherte IP-Adresse. Aus der
              IP wird zusammen mit einem täglich wechselnden Zufallswert eine Prüfsumme gebildet;
              sie erlaubt, wiederkehrende Besuche innerhalb eines Tages zu erkennen, aber keine
              Wiedererkennung darüber hinaus und keinen Rückschluss auf die Person.</span></span>
        </label>
        <label class="haken">
          <input type="checkbox" name="cookie_banner" value="1"
                 <?= Tenant::einstellung('cookie_banner', false) ? ' checked' : '' ?>>
          <span class="haken__text">Cookie-Hinweis auf der Website anzeigen
            <span class="haken__hinweis">Für die eigene Zählung ist keine Einwilligung nötig, weil
              keine Cookies gesetzt und keine Daten an Dritte gegeben werden. Wer zusätzlich
              Karten, Videos oder externe Schriften einbindet, braucht einen Hinweis.</span></span>
        </label>
      </div>

      <div class="feld mt-4">
        <label class="feld__label" for="auftragsverarbeiter">Eingesetzte Dienstleister</label>
        <textarea class="eingabe" id="auftragsverarbeiter" name="auftragsverarbeiter" rows="3" data-waechst
          placeholder="Hosting: … · Zahlungen: Stripe Payments Europe Ltd. · E-Mail-Versand: …"><?= Util::h((string) Tenant::einstellung('auftragsverarbeiter', '')) ?></textarea>
        <div class="feld__hinweis">Für das Verzeichnis von Verarbeitungstätigkeiten und die
          Datenschutzerklärung. Mit jedem Dienstleister braucht es einen Vertrag zur
          Auftragsverarbeitung.</div>
      </div>
    </div>
    <div class="karte__fuss">
      <span class="klein gedimmt">Letzte Wartung:
        <?= Tenant::einstellung('wartung_letzter_lauf', 0)
            ? Util::h(Util::relativ(date('Y-m-d H:i:s', (int) Tenant::einstellung('wartung_letzter_lauf', 0))))
            : 'noch nicht gelaufen' ?></span>
      <div class="fueller"></div>
      <button class="btn btn--primaer" type="submit">Speichern</button>
    </div>
  </form>

  <form method="post" class="karte mt-4" style="max-width:760px">
    <?= Auth::csrfFeld() ?>
    <input type="hidden" name="aktion" value="wartung">
    <div class="karte__koerper reihe">
      <div style="flex:1">
        <div class="halbfett">Jetzt aufräumen</div>
        <div class="klein gedimmt">Löscht alles, was über den eingestellten Fristen liegt.</div>
      </div>
      <button class="btn" type="submit"><?= Icon::svg('refresh', 15) ?> Ausführen</button>
    </div>
  </form>
</div>

<div class="hinweis hinweis--still mt-5">
  <?= Icon::svg('info', 17) ?>
  <div class="hinweis__text">Diese Seite hilft beim Einhalten der Pflichten, ist aber keine
    Rechtsberatung. Die Vorlagen für Impressum, Datenschutzerklärung und AGB sind Textgerüste
    zum Anpassen – ob sie für den eigenen Betrieb passen, prüft am besten eine fachkundige Person.</div>
</div>

<script>
/* Der Auswahlknopf für den Export baut die Adresse selbst zusammen –
   ein eigenes Formular dafür wäre umständlicher als drei Zeilen hier. */
(function () {
  var wahl = document.getElementById('ds-kunde'), knopf = document.getElementById('ds-export');
  if (!wahl || !knopf) { return; }
  var basis = knopf.getAttribute('href').replace(/id=\d+$/, 'id=');
  function auffrischen() {
    knopf.setAttribute('href', basis + wahl.value);
    knopf.classList.toggle('nur-lesbar', wahl.value === '0');
  }
  wahl.addEventListener('change', auffrischen);
  auffrischen();
})();
</script>

<?php require __DIR__ . '/partials/fuss.php'; ?>
