<?php
/**
 * Einzelne Rechnung.
 *
 * Gelöscht wird hier nie: Eine Rechnungsnummer ist vergeben und muss
 * lückenlos bleiben. Wer eine Rechnung zurücknehmen will, storniert sie –
 * dabei entsteht eine Gutschrift mit negativem Betrag, die auf das
 * Original verweist. Das ist nicht Bequemlichkeit, das ist Vorschrift.
 */
require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/partials/helfer.php';

Auth::fordern('modul.invoices');

$istNeu = App::get('id') === 'neu';
$id = $istNeu ? 0 : App::getInt('id');
$rechnung = $istNeu ? null : Tenant::find('invoices', $id);

if (!$istNeu && !$rechnung) {
    App::melden('Diese Rechnung wurde nicht gefunden.', 'fehler');
    App::weiter('/app/rechnungen.php');
}

if (App::istPost()) {
    Auth::csrfFordern();
    Auth::fordern('invoices.write');
    $aktion = App::aktion();

    if ($aktion === 'anlegen') {
        $positionen = [];
        $titel = App::postListe('pos_titel');
        $menge = App::postListe('pos_menge');
        $preis = App::postListe('pos_preis');
        foreach ($titel as $i => $t) {
            if (trim($t) === '') {
                continue;
            }
            $positionen[] = [
                'titel' => $t,
                'menge' => max(1, (int) ($menge[$i] ?? 1)),
                'preis_cent' => Util::centAus((string) ($preis[$i] ?? '0')),
                'steuersatz' => App::postInt('steuersatz', 19),
            ];
        }
        if ($positionen === []) {
            App::melden('Mindestens eine Position mit Titel angeben.', 'fehler');
        } else {
            $neueId = Invoices::erstellen($positionen, [
                'customer_id' => App::postInt('customer_id'),
                'datum' => App::post('datum', Util::heute()),
                'faellig' => App::post('faellig'),
                'notiz' => App::post('notiz'),
                'status' => App::post('status', 'offen'),
            ]);
            App::melden('Rechnung erstellt.');
            App::weiter('/app/rechnung.php?id=' . $neueId);
        }
    }

    if ($aktion === 'bezahlt' && $id > 0) {
        Invoices::alsBezahlt($id);
        App::melden('Als bezahlt verbucht.');
        App::weiter('/app/rechnung.php?id=' . $id);
    }

    if ($aktion === 'storno' && $id > 0) {
        $gutschrift = Invoices::stornieren($id, App::post('grund'));
        App::melden('Rechnung storniert. Es wurde eine Gutschrift erzeugt.');
        App::weiter('/app/rechnung.php?id=' . ($gutschrift ?: $id));
    }

    if ($aktion === 'versenden' && $id > 0) {
        $r = Tenant::find('invoices', $id);
        $empfaenger = Util::ausJson((string) $r['empfaenger']);
        if (($empfaenger['email'] ?? '') === '') {
            App::melden('Für diese Rechnung ist keine E-Mail-Adresse hinterlegt.', 'fehler');
        } else {
            Mail::senden((string) $empfaenger['email'], 'Deine Rechnung ' . $r['nummer'],
                "Hallo " . ($empfaenger['name'] ?? '') . ",\n\n"
                . "anbei die Rechnung " . $r['nummer'] . " über " . Util::geld((int) $r['summe_cent']) . ".\n\n"
                . "Zahlbar bis " . Util::datum((string) $r['faellig']) . ".\n\n"
                . "Vielen Dank!",
                ['customer_id' => (int) $r['customer_id']]);
            Tenant::update('invoices', $id, ['versendet_am' => Util::jetzt()]);
            App::melden('Rechnung versendet.');
        }
        App::weiter('/app/rechnung.php?id=' . $id);
    }
}

/* ---------------------------------------------------------------- Neu - */

if ($istNeu) {
    Auth::fordern('invoices.write');
    $kundeId = App::getInt('kunde');
    $titel = 'Rechnung erstellen';
    $brotkrumen = [['Rechnungen', '/app/rechnungen.php'], ['Neu', null]];
    require __DIR__ . '/partials/kopf.php';
    ?>
    <form method="post" class="karte" style="max-width:900px">
      <?= Auth::csrfFeld() ?>
      <input type="hidden" name="aktion" value="anlegen">
      <div class="karte__koerper">
        <div class="feld-reihe feld-reihe--3">
          <div class="feld"><label class="feld__label" for="kunde">Kunde</label>
            <select id="kunde" name="customer_id" required>
              <option value="0">Ohne Kundenakte</option>
              <?php foreach (Tenant::all('customers', '', [], 'nachname, vorname') as $k): ?>
                <option value="<?= (int) $k['id'] ?>"<?= $kundeId === (int) $k['id'] ? ' selected' : '' ?>>
                  <?= Util::h(Customers::name($k)) ?></option>
              <?php endforeach; ?>
            </select></div>
          <div class="feld"><label class="feld__label" for="datum">Rechnungsdatum</label>
            <input class="eingabe" id="datum" type="date" name="datum" value="<?= Util::attr(Util::heute()) ?>"></div>
          <div class="feld"><label class="feld__label" for="faellig">Fällig am</label>
            <input class="eingabe" id="faellig" type="date" name="faellig"
                   value="<?= Util::attr(date('Y-m-d', strtotime('+' . (int) Tenant::einstellung('zahlungsziel_tage', 14) . ' days'))) ?>"></div>
        </div>

        <div class="versal gedimmt-2 mb-3">Positionen</div>
        <div id="positionen">
          <?php for ($i = 0; $i < 3; $i++): ?>
            <div class="reihe reihe--eng mb-2">
              <input class="eingabe" name="pos_titel[]" placeholder="Leistung" style="flex:3"
                     <?= $i === 0 ? 'list="leistungsliste"' : '' ?>>
              <input class="eingabe" type="number" name="pos_menge[]" value="1" min="1" style="width:80px"
                     aria-label="Menge">
              <div class="eingabe-gruppe" style="width:150px">
                <input class="eingabe" name="pos_preis[]" placeholder="0,00" aria-label="Einzelpreis">
                <span class="eingabe-gruppe__anhang"><?= Util::h(Tenant::waehrung()) ?></span>
              </div>
            </div>
          <?php endfor; ?>
          <datalist id="leistungsliste">
            <?php foreach (Tenant::all('services', 'aktiv = 1', [], 'position') as $s): ?>
              <option value="<?= Util::attr((string) $s['name']) ?>"></option>
            <?php endforeach; ?>
          </datalist>
        </div>

        <div class="feld-reihe feld-reihe--2 mt-4">
          <div class="feld"><label class="feld__label" for="steuersatz">Steuersatz</label>
            <select id="steuersatz" name="steuersatz">
              <option value="19">19 % (Regelsatz)</option>
              <option value="7">7 % (ermäßigt)</option>
              <option value="0">0 % (Kleinunternehmer / steuerfrei)</option>
            </select></div>
          <div class="feld"><label class="feld__label" for="status">Status</label>
            <select id="status" name="status">
              <option value="offen">Offen</option>
              <option value="entwurf">Entwurf (noch keine Nummer)</option>
              <option value="bezahlt">Bereits bezahlt</option>
            </select></div>
        </div>
        <div class="feld"><label class="feld__label" for="notiz">Notiz auf der Rechnung</label>
          <textarea class="eingabe" id="notiz" name="notiz" rows="2" data-waechst
                    placeholder="Vielen Dank für dein Vertrauen."></textarea></div>
      </div>
      <div class="karte__fuss">
        <span class="klein gedimmt">Preise sind Bruttopreise – die Steuer wird herausgerechnet.</span>
        <div class="fueller"></div>
        <a class="btn" href="<?= Util::attr(App::url('/app/rechnungen.php')) ?>">Abbrechen</a>
        <button class="btn btn--primaer" type="submit">Rechnung erstellen</button>
      </div>
    </form>
    <?php
    require __DIR__ . '/partials/fuss.php';
    exit;
}

/* ------------------------------------------------------------ Ansicht - */

$positionen = Invoices::positionen($id);
$empfaenger = Util::ausJson((string) $rechnung['empfaenger']);
$storno = (int) $rechnung['storno_von'] > 0 ? Tenant::find('invoices', (int) $rechnung['storno_von']) : null;

$titel = ((string) $rechnung['art'] === 'gutschrift' ? 'Gutschrift ' : 'Rechnung ')
       . ($rechnung['nummer'] ?: 'Entwurf');
$unter = Util::datum((string) $rechnung['datum']) . ' · ' . Util::geld((int) $rechnung['summe_cent']);
$brotkrumen = [['Rechnungen', '/app/rechnungen.php'], [(string) ($rechnung['nummer'] ?: 'Entwurf'), null]];

$aktionen = '<a class="btn" target="_blank" rel="noopener" href="'
          . App::url('/app/pdf.php?art=rechnung&id=' . $id) . '">' . Icon::svg('download', 15) . ' PDF</a>';
if (Auth::darf('invoices.write') && (string) $rechnung['status'] !== 'storniert') {
    if ((string) $rechnung['status'] !== 'bezahlt') {
        $aktionen .= '<form method="post" style="display:inline">' . Auth::csrfFeld()
                   . '<input type="hidden" name="aktion" value="bezahlt">'
                   . '<button class="btn btn--primaer" type="submit">' . Icon::svg('check', 15)
                   . ' Als bezahlt verbuchen</button></form>';
    }
    $aktionen .= '<div class="aufklapp"><button class="btn" data-aufklapp>' . Icon::svg('more', 15) . '</button>'
               . '<div class="aufklapp__menue">'
               . '<form method="post"><input type="hidden" name="aktion" value="versenden">'
               . Auth::csrfFeld()
               . '<button class="aufklapp__eintrag" type="submit">' . Icon::svg('send', 16) . ' Per E-Mail senden</button></form>'
               . '<div class="aufklapp__trenner"></div>'
               . '<button class="aufklapp__eintrag aufklapp__eintrag--gefahr" data-modal-auf="modal-storno">'
               . Icon::svg('x', 16) . ' Stornieren</button>'
               . '</div></div>';
}
require __DIR__ . '/partials/kopf.php';
?>

<?php if ((string) $rechnung['status'] === 'storniert'): ?>
  <div class="hinweis hinweis--gefahr mb-4">
    <?= Icon::svg('alert', 17) ?>
    <div class="hinweis__text">Diese Rechnung wurde storniert. Es existiert eine Gutschrift
      mit dem Gegenbetrag.</div>
  </div>
<?php elseif ($storno): ?>
  <div class="hinweis hinweis--still mb-4">
    <?= Icon::svg('info', 17) ?>
    <div class="hinweis__text">Gutschrift zu
      <a href="<?= Util::attr(App::url('/app/rechnung.php?id=' . (int) $storno['id'])) ?>">
        Rechnung <?= Util::h((string) $storno['nummer']) ?></a>.</div>
  </div>
<?php endif; ?>

<div class="raster raster--haupt-neben">
  <div class="karte">
    <div class="karte__koerper" style="padding:var(--r7)">
      <div class="reihe" style="align-items:flex-start;margin-bottom:var(--r7)">
        <div>
          <div style="font-size:17px;font-weight:660;color:var(--marke)"><?= Util::h(Tenant::name()) ?></div>
          <div class="klein gedimmt mt-2" style="white-space:pre-line"><?= Util::h((string) Tenant::einstellung('rechnung_absender', '')) ?></div>
        </div>
        <div class="fueller"></div>
        <div style="text-align:right">
          <h2><?= (string) $rechnung['art'] === 'gutschrift' ? 'Gutschrift' : 'Rechnung' ?></h2>
          <div class="mono gedimmt mt-2"><?= Util::h((string) ($rechnung['nummer'] ?: 'Entwurf')) ?></div>
        </div>
      </div>

      <div class="reihe" style="align-items:flex-start;margin-bottom:var(--r6)">
        <div>
          <div class="versal gedimmt-2 mb-2">Rechnung an</div>
          <div class="halbfett"><?= Util::h((string) ($empfaenger['name'] ?? '')) ?></div>
          <div class="klein gedimmt">
            <?= Util::h((string) ($empfaenger['strasse'] ?? '')) ?><br>
            <?= Util::h(trim(((string) ($empfaenger['plz'] ?? '')) . ' ' . ((string) ($empfaenger['ort'] ?? '')))) ?>
          </div>
        </div>
        <div class="fueller"></div>
        <div class="klein" style="text-align:right">
          <?php foreach ([
            ['Rechnungsdatum', Util::datum((string) $rechnung['datum'])],
            ['Fällig am', Util::datum((string) $rechnung['faellig'])],
            ['Status', Invoices::statusName((string) $rechnung['status'])],
          ] as [$l, $w]): ?>
            <div class="reihe" style="justify-content:flex-end;gap:var(--r4)">
              <span class="gedimmt"><?= Util::h($l) ?></span>
              <span class="halbfett" style="min-width:100px"><?= Util::h($w) ?></span>
            </div>
          <?php endforeach; ?>
        </div>
      </div>

      <table class="tabelle" style="margin-bottom:var(--r5)">
        <thead><tr><th>Leistung</th><th class="zahl">Menge</th>
          <th class="zahl">Einzelpreis</th><th class="zahl">Summe</th></tr></thead>
        <tbody>
        <?php foreach ($positionen as $pos): ?>
          <tr>
            <td class="haupt"><?= Util::h((string) $pos['titel']) ?>
              <?php if ((string) $pos['beschreibung'] !== ''): ?>
                <div class="winzig gedimmt-2"><?= Util::h((string) $pos['beschreibung']) ?></div>
              <?php endif; ?>
            </td>
            <td class="zahl"><?= (int) $pos['menge'] ?></td>
            <td class="zahl tabnum"><?= Util::h(Util::geld((int) $pos['einzelpreis_cent'])) ?></td>
            <td class="zahl tabnum halbfett"><?= Util::h(Util::geld((int) $pos['summe_cent'])) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>

      <div style="max-width:300px;margin-left:auto">
        <div class="stapel stapel--eng">
          <div class="reihe"><span class="gedimmt">Netto</span><div class="fueller"></div>
            <span class="tabnum"><?= Util::h(Util::geld((int) $rechnung['netto_cent'])) ?></span></div>
          <div class="reihe"><span class="gedimmt">Umsatzsteuer</span><div class="fueller"></div>
            <span class="tabnum"><?= Util::h(Util::geld((int) $rechnung['steuer_cent'])) ?></span></div>
          <hr style="margin:var(--r2) 0">
          <div class="reihe" style="font-size:17px">
            <span class="fett">Gesamt</span><div class="fueller"></div>
            <span class="fett tabnum" style="color:var(--marke)">
              <?= Util::h(Util::geld((int) $rechnung['summe_cent'])) ?></span></div>
          <?php if ((int) $rechnung['bezahlt_cent'] > 0): ?>
            <div class="reihe klein"><span class="gedimmt">Bezahlt</span><div class="fueller"></div>
              <span class="tabnum" style="color:var(--erfolg)">
                <?= Util::h(Util::geld((int) $rechnung['bezahlt_cent'])) ?></span></div>
          <?php endif; ?>
        </div>
      </div>

      <?php if ((string) $rechnung['notiz'] !== ''): ?>
        <div class="klein gedimmt mt-6"><?= nl2br(Util::h((string) $rechnung['notiz'])) ?></div>
      <?php endif; ?>
    </div>
  </div>

  <div class="stapel">
    <?php if ((int) $rechnung['customer_id'] > 0):
      $kunde = Tenant::find('customers', (int) $rechnung['customer_id']);
      if ($kunde): ?>
      <div class="karte">
        <div class="karte__kopf"><h3>Kunde</h3></div>
        <div class="karte__koerper">
          <?= person(Customers::name($kunde), ['unter' => (string) $kunde['email'],
                'url' => '/app/kunde.php?id=' . (int) $kunde['id'], 'gross' => true]) ?>
        </div>
      </div>
      <?php endif;
    endif; ?>

    <?php /*
     * Die Termine hinter der Rechnung.
     *
     * Auf dem Beleg steht das Datum in der Positionsbeschreibung, damit
     * der Kunde es liest. Hier steht der Weg zurueck in den Kalender -
     * fuer die Frage "welche Stunde war das noch?", die beim Nachfassen
     * immer kommt.
     */
    $dazuTermine = Invoices::termine($id);
    if ($dazuTermine !== []): ?>
      <div class="karte">
        <div class="karte__kopf"><h3>Abgerechnete Termine</h3>
          <span class="pille"><?= count($dazuTermine) ?></span></div>
        <div class="karte__koerper karte__koerper--eng">
          <div class="stapel stapel--eng">
            <?php foreach ($dazuTermine as $tm): ?>
              <a class="reihe" href="<?= Util::attr(App::url('/app/buchung.php?id=' . (int) $tm['id'])) ?>">
                <span class="klein gedimmt" style="min-width:74px">
                  <?= Util::h(Util::datum((string) $tm['start'], false)) ?></span>
                <span style="flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
                  <?= Util::h((string) $tm['titel']) ?></span>
                <span class="klein gedimmt"><?= Util::h(Util::uhrzeit((string) $tm['start'])) ?></span>
              </a>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
    <?php endif; ?>

    <div class="karte">
      <div class="karte__kopf"><h3>Verlauf</h3></div>
      <div class="karte__koerper karte__koerper--eng">
        <div class="strahl">
          <div class="strahl__punkt strahl__punkt--marke">
            <div class="strahl__zeit"><?= Util::h(Util::datumZeit((string) $rechnung['erstellt'])) ?></div>
            <div class="strahl__titel">Erstellt</div>
          </div>
          <?php if ($rechnung['versendet_am']): ?>
            <div class="strahl__punkt">
              <div class="strahl__zeit"><?= Util::h(Util::datumZeit((string) $rechnung['versendet_am'])) ?></div>
              <div class="strahl__titel">Versendet</div>
            </div>
          <?php endif; ?>
          <?php if ($rechnung['bezahlt_am']): ?>
            <div class="strahl__punkt strahl__punkt--erfolg">
              <div class="strahl__zeit"><?= Util::h(Util::datumZeit((string) $rechnung['bezahlt_am'])) ?></div>
              <div class="strahl__titel">Bezahlt</div>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <div class="hinweis hinweis--still">
      <?= Icon::svg('shield', 17) ?>
      <div class="hinweis__text">Rechnungen werden nicht gelöscht. Die Nummernfolge muss
        lückenlos sein – zurückgenommen wird über eine Gutschrift.</div>
    </div>
  </div>
</div>

<?php if (Auth::darf('invoices.write')): ?>
<dialog class="modal modal--schmal" id="modal-storno">
  <form method="post">
    <?= Auth::csrfFeld() ?>
    <input type="hidden" name="aktion" value="storno">
    <div class="modal__kopf"><h2>Rechnung stornieren</h2></div>
    <div class="modal__koerper">
      <p>Es entsteht eine Gutschrift über <strong><?= Util::h(Util::geld((int) $rechnung['summe_cent'])) ?></strong>,
        die auf diese Rechnung verweist. Beide Belege bleiben bestehen.</p>
      <div class="feld"><label class="feld__label" for="st-grund">Grund</label>
        <input class="eingabe" id="st-grund" name="grund" placeholder="Leistung nicht erbracht"></div>
    </div>
    <div class="modal__fuss"><div class="fueller"></div>
      <button type="button" class="btn" data-modal-zu>Abbrechen</button>
      <button type="submit" class="btn btn--gefahr">Stornieren</button></div>
  </form>
</dialog>
<?php endif; ?>

<?php require __DIR__ . '/partials/fuss.php'; ?>
