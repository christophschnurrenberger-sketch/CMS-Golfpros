<?php
/**
 * Eine Rechnung: Entwurf bearbeiten oder – ausgestellt – ansehen,
 * versenden, als bezahlt vermerken, stornieren.
 */
require __DIR__ . '/partials/start.php';
Betreiber::fordern('rechnungen.view');

$id = App::getInt('id', App::postInt('id'));
$r = $id > 0 ? Betreiberrechnungen::finden($id) : null;
$instanzId = $r !== null ? (int) $r['instanz_id'] : App::getInt('instanz', App::postInt('instanz'));
$ws = Instanzen::finden($instanzId);
if ($id > 0 && $r === null) {
    App::melden('Diese Rechnung gibt es nicht.', 'fehler');
    App::weiter('/master/rechnungen.php');
}
if ($r === null && $ws === null) {
    App::melden('Bitte zuerst eine Instanz wählen.', 'fehler');
    App::weiter('/master/rechnungen.php');
}

/* --------------------------------------------------------- Handlungen --- */

if (App::istPost()) {
    Auth::csrfFordern();
    Betreiber::fordern('rechnungen.write');
    $aktion = App::aktion();
    $hier = '/master/rechnung.php?id=' . $id;

    if (in_array($aktion, ['speichern', 'ausstellen'], true) && ($r === null || (string) $r['status'] === 'entwurf')) {
        $empfaenger = ['firma' => App::post('e_firma'), 'name' => App::post('e_name'), 'strasse' => App::post('e_strasse'),
                       'plz' => App::post('e_plz'), 'ort' => App::post('e_ort'), 'land' => App::post('e_land'),
                       'email' => App::post('e_email'), 'ust_id' => App::post('e_ust_id')];
        $kopf = ['steuerfall' => App::post('steuerfall'), 'datum' => App::post('datum'), 'leistung_von' => App::post('leistung_von'),
                 'leistung_bis' => App::post('leistung_bis'), 'text_oben' => App::postRoh('text_oben'), 'notiz' => App::postRoh('notiz'),
                 'notiz_intern' => App::postRoh('notiz_intern'), 'abo_id' => App::postInt('abo_id'), 'empfaenger' => $empfaenger];
        [$neu, $fehler] = Betreiberrechnungen::entwurfSpeichern($id, $instanzId, $kopf, Betreiberrechnungen::positionenAusFormular($_POST));
        if ($neu === 0) {
            App::melden(implode(' ', $fehler), 'fehler');
            App::weiter($id > 0 ? $hier : '/master/rechnung.php?instanz=' . $instanzId);
        }
        if (App::postBool('als_standard')) {
            $f = Betreiberrechnungen::rechnungsdatenSpeichern($instanzId, $empfaenger);
            if ($f !== []) {
                App::melden(implode(' ', $f), 'fehler');
            }
        }
        if ($aktion === 'ausstellen') {
            [$ok, $meldung] = Betreiberrechnungen::ausstellen($neu);
            App::melden($meldung, $ok ? 'erfolg' : 'fehler');
        } else {
            App::melden('Entwurf gespeichert.');
        }
        App::weiter('/master/rechnung.php?id=' . $neu);
    }
    if ($aktion === 'loeschen') {
        App::melden(Betreiberrechnungen::entwurfLoeschen($id) ? 'Entwurf gelöscht.' : 'Nur Entwürfe lassen sich löschen.',
            (string) ($r['status'] ?? '') === 'entwurf' ? 'erfolg' : 'fehler');
        App::weiter('/master/rechnungen.php');
    }
    if ($aktion === 'senden') {
        [$ok, $meldung] = Betreiberrechnungen::senden($id);
        App::melden($meldung, $ok ? 'erfolg' : 'fehler');
    }
    if ($aktion === 'bezahlt' || $aktion === 'offen') {
        [$ok, $meldung] = Betreiberrechnungen::bezahltSetzen($id, $aktion === 'bezahlt', App::post('am'));
        App::melden($meldung, $ok ? 'erfolg' : 'fehler');
    }
    if ($aktion === 'stornieren') {
        [$ok, $meldung, $stornoId] = Betreiberrechnungen::stornieren($id, App::post('grund'));
        App::melden($meldung, $ok ? 'erfolg' : 'fehler');
        if ($ok) {
            App::weiter('/master/rechnung.php?id=' . $stornoId);
        }
    }
    if ($aktion === 'notiz_intern' && $r !== null) {
        DB::update('betreiber_rechnungen', ['notiz_intern' => mb_substr(trim(App::postRoh('notiz_intern')), 0, 2000)], 'id = :id', ['id' => $id]);
        App::melden('Interne Notiz gespeichert.');
    }
    App::weiter($hier);
}

/* ------------------------------------------------------------- Daten --- */

$entwurf = $r === null || (string) $r['status'] === 'entwurf';
$hinweisVertrag = '';
if ($r !== null) {
    $kopf = $r;
    $kopf['empfaenger'] = Util::ausJson((string) $r['empfaenger'], []);
    $positionen = Betreiberrechnungen::positionen($id);
} elseif (App::get('vertrag') === '1') {
    $v = Betreiberrechnungen::vorschlagAusVertrag($instanzId);
    $kopf = $v['kopf'];
    $positionen = $v['positionen'];
    $hinweisVertrag = $v['hinweis'];
} else {
    $emp = Betreiberrechnungen::rechnungsdaten($instanzId);
    unset($emp['gespeichert']);
    $kopf = ['steuerfall' => Betreiberrechnungen::steuerfallVorschlag($emp), 'empfaenger' => $emp,
             'text_oben' => (string) Plattform::einstellung('rg_text')];
    $positionen = [];
}
$tag = static fn ($d) => $d ? substr((string) $d, 0, 10) : '';
$emp = (array) ($kopf['empfaenger'] ?? []);
$satz = (int) Plattform::einstellung('rg_steuersatz');
$luecken = Betreiberrechnungen::absenderLuecken();

$titel = $r === null ? 'Neue Rechnung' : ((string) $r['nummer'] !== ''
    ? ((string) $r['art'] === 'storno' ? 'Stornorechnung ' : 'Rechnung ') . $r['nummer'] : 'Rechnungsentwurf #' . $id);
$unter = ($r !== null ? Betreiberrechnungen::statusPille((string) $r['status'], $r['faellig'] ? (string) $r['faellig'] : null) . ' ' : '')
       . ($ws !== null ? '<a href="' . Util::attr(App::url('/master/instanz.php?id=' . $instanzId . '&reiter=rechnungen')) . '">'
          . Util::h((string) $ws['name']) . '</a>' : '<span class="gedimmt">Instanz #' . $instanzId . ' (gelöscht)</span>');
$bereich = 'rechnungen';
$brotkrumen = [['Übersicht', '/master/'], ['Rechnungen', '/master/rechnungen.php'], [$titel, null]];
$aktionen = $r !== null ? '<a class="btn" href="' . Util::attr(App::url('/master/rechnung-pdf.php?id=' . $id)) . '" target="_blank" rel="noopener">'
          . Icon::svg('external', 15) . ($entwurf ? ' Vorschau (PDF)' : ' PDF öffnen') . '</a>' : '';
$skripte = '<script src="' . Util::attr(App::asset('assets/js/master.js')) . '"></script>';
require __DIR__ . '/partials/kopf.php';
?>

<?php if ($entwurf): ?>
  <?php if ($hinweisVertrag !== ''): ?>
    <div class="hinweis hinweis--still mb-4"><?= Icon::svg('info', 17) ?><div class="hinweis__text"><?= Util::h($hinweisVertrag) ?></div></div>
  <?php endif; ?>
  <?php if ($luecken !== []): ?>
    <div class="hinweis hinweis--warnung mb-4"><?= Icon::svg('alert', 17) ?>
      <div class="hinweis__text">Zum Ausstellen fehlt im Rechnungsabsender noch: <b><?= Util::h(implode(', ', $luecken)) ?></b>.
        <a href="<?= Util::attr(App::url('/master/einstellungen.php#rechnungsabsender')) ?>">Ergänzen</a></div></div>
  <?php endif; ?>

  <form method="post" action="<?= Util::attr(App::url('/master/rechnung.php')) ?>" class="stapel" data-rechnung>
    <?= Auth::csrfFeld() ?>
    <input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="instanz" value="<?= $instanzId ?>">
    <input type="hidden" name="abo_id" value="<?= (int) ($kopf['abo_id'] ?? 0) ?>">
    <div class="raster raster--haupt-neben">
      <?= karteAuf('Rechnung') ?>
        <div class="karte__koerper">
          <div class="feld-reihe feld-reihe--2">
            <div class="feld"><label class="feld__label" for="r-steuerfall">Steuer</label>
              <select class="eingabe" id="r-steuerfall" name="steuerfall" data-steuerfall>
                <?php foreach (Betreiberrechnungen::STEUERFALL as $k => $v): ?>
                  <option value="<?= $k ?>"<?= ($kopf['steuerfall'] ?? 'regel') === $k ? ' selected' : '' ?>><?= Util::h($v) ?></option>
                <?php endforeach; ?>
              </select>
              <div class="feld__hinweis">Vorschlag aus Land und USt-IdNr. des Empfängers. Im Zweifel die Steuerberatung fragen.</div></div>
            <div class="feld"><label class="feld__label" for="r-datum">Rechnungsdatum</label>
              <input class="eingabe" id="r-datum" type="date" name="datum" value="<?= Util::attr($tag($kopf['datum'] ?? '')) ?>">
              <div class="feld__hinweis">Leer = Tag des Ausstellens. Fällig nach <?= Plattform::zahl('rg_zahlungsziel') ?> Tagen.</div></div>
            <div class="feld"><label class="feld__label" for="r-von">Leistung von</label>
              <input class="eingabe" id="r-von" type="date" name="leistung_von" value="<?= Util::attr($tag($kopf['leistung_von'] ?? '')) ?>"></div>
            <div class="feld"><label class="feld__label" for="r-bis">Leistung bis</label>
              <input class="eingabe" id="r-bis" type="date" name="leistung_bis" value="<?= Util::attr($tag($kopf['leistung_bis'] ?? '')) ?>"></div>
          </div>
          <div class="feld"><label class="feld__label" for="r-oben">Einleitung</label>
            <textarea class="eingabe" id="r-oben" name="text_oben" rows="2" maxlength="2000"><?= Util::h((string) ($kopf['text_oben'] ?? '')) ?></textarea></div>
        </div>
      </div>
      <?= karteAuf('Empfänger') ?>
        <div class="karte__koerper">
          <?php foreach (['firma' => 'Firma', 'name' => 'Ansprechpartner', 'strasse' => 'Straße und Hausnummer'] as $k => $l): ?>
            <div class="feld"><label class="feld__label" for="e-<?= $k ?>"><?= $l ?></label>
              <input class="eingabe" id="e-<?= $k ?>" name="e_<?= $k ?>" value="<?= Util::attr((string) ($emp[$k] ?? '')) ?>"></div>
          <?php endforeach; ?>
          <div class="feld-reihe feld-reihe--3">
            <div class="feld"><label class="feld__label" for="e-plz">PLZ</label>
              <input class="eingabe" id="e-plz" name="e_plz" value="<?= Util::attr((string) ($emp['plz'] ?? '')) ?>"></div>
            <div class="feld" style="grid-column: span 2"><label class="feld__label" for="e-ort">Ort</label>
              <input class="eingabe" id="e-ort" name="e_ort" value="<?= Util::attr((string) ($emp['ort'] ?? '')) ?>"></div>
          </div>
          <div class="feld-reihe feld-reihe--2">
            <div class="feld"><label class="feld__label" for="e-land">Land</label>
              <input class="eingabe" id="e-land" name="e_land" maxlength="2" list="laender" value="<?= Util::attr((string) ($emp['land'] ?? 'DE')) ?>">
              <datalist id="laender"><?php foreach (Betreiberrechnungen::LAENDER as $c => $n): ?><option value="<?= $c ?>"><?= Util::h($n) ?></option><?php endforeach; ?></datalist></div>
            <div class="feld"><label class="feld__label" for="e-ust">USt-IdNr.</label>
              <input class="eingabe" id="e-ust" name="e_ust_id" value="<?= Util::attr((string) ($emp['ust_id'] ?? '')) ?>" placeholder="nur bei Bedarf"></div>
          </div>
          <div class="feld"><label class="feld__label" for="e-email">E-Mail für den Versand</label>
            <input class="eingabe" id="e-email" type="email" name="e_email" value="<?= Util::attr((string) ($emp['email'] ?? '')) ?>"></div>
          <label class="haken"><input type="checkbox" name="als_standard" value="1">
            <span class="haken__text">Als Rechnungsanschrift der Instanz merken</span></label>
        </div>
      </div>
    </div>

    <?= karteAuf('Positionen', '<span class="klein gedimmt">Preise netto – die Umsatzsteuer kommt hinzu</span>') ?>
      <div class="karte__koerper">
          <div class="tabelle-huelle"><table class="tabelle tabelle--eng positionen" data-positionen>
            <thead><tr><th>Beschreibung</th><th class="zahl">Menge</th><th>Einheit</th><th class="zahl">Einzelpreis netto</th><th>USt</th><th class="zahl">Betrag</th><th><span class="nur-lesbar">Entfernen</span></th></tr></thead>
            <tbody>
              <?php $zeilen = $positionen;
              $zeilen[] = ['text' => '', 'menge_hundertstel' => 100, 'einheit' => 'Monat', 'einzel_cent' => 0, 'steuersatz' => $satz, 'leer' => true];
              foreach ($zeilen as $p): $leer = !empty($p['leer']); ?>
                <tr data-position>
                  <td><textarea class="eingabe" name="text[]" rows="1" maxlength="500" aria-label="Beschreibung" placeholder="<?= $leer ? 'Weitere Position …' : '' ?>"><?= Util::h((string) $p['text']) ?></textarea></td>
                  <td><input class="eingabe eingabe--zahl" name="menge[]" inputmode="decimal" aria-label="Menge"
                             value="<?= Util::attr(rtrim(rtrim(number_format((int) $p['menge_hundertstel'] / 100, 2, ',', ''), '0'), ',')) ?>"></td>
                  <td><input class="eingabe" name="einheit[]" list="einheiten" maxlength="24" aria-label="Einheit" value="<?= Util::attr((string) $p['einheit']) ?>"></td>
                  <td><input class="eingabe eingabe--zahl" name="einzel[]" inputmode="decimal" aria-label="Einzelpreis netto"
                             value="<?= !$leer || (int) $p['einzel_cent'] !== 0 ? Util::attr(number_format((int) $p['einzel_cent'] / 100, 2, ',', '')) : '' ?>"></td>
                  <td><select class="eingabe" name="steuersatz[]" aria-label="Umsatzsteuer">
                    <?php foreach ([19, 7, 0] as $s): ?><option value="<?= $s ?>"<?= (int) $p['steuersatz'] === $s ? ' selected' : '' ?>><?= $s ?> %</option><?php endforeach; ?>
                  </select></td>
                  <td class="zahl" data-zeilensumme>—</td>
                  <td><button type="button" class="rundknopf rundknopf--klein" data-position-weg aria-label="Position entfernen"><?= Icon::svg('x', 14) ?></button></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table></div>
          <datalist id="einheiten"><?php foreach (Betreiberrechnungen::EINHEITEN as $e): ?><option value="<?= Util::attr($e) ?>"><?php endforeach; ?></datalist>
          <div class="reihe reihe--zwischen mt-3">
            <button type="button" class="btn btn--klein" data-position-neu><?= Icon::svg('plus', 14) ?> Position</button>
            <div class="summenblock" data-summen aria-live="polite">
              <span>Netto <b data-summe-netto>—</b></span><span>USt <b data-summe-steuer>—</b></span><span>Gesamt <b data-summe-brutto>—</b></span>
            </div>
          </div>
      </div>
    </div>

    <div class="raster raster--haupt-neben">
      <?= karteAuf('Hinweise') ?>
        <div class="karte__koerper">
          <div class="feld"><label class="feld__label" for="r-notiz">Hinweis auf der Rechnung</label>
            <textarea class="eingabe" id="r-notiz" name="notiz" rows="2" maxlength="2000"
                      placeholder="z. B. Vielen Dank für die Zusammenarbeit."><?= Util::h((string) ($kopf['notiz'] ?? '')) ?></textarea>
            <div class="feld__hinweis" data-steuerhinweis>Der Steuerhinweis (§ 19, Reverse Charge, Drittland) wird automatisch ergänzt.</div></div>
          <div class="feld"><label class="feld__label" for="r-intern">Interne Notiz <span class="winzig gedimmt-2">steht nicht auf der Rechnung</span></label>
            <textarea class="eingabe" id="r-intern" name="notiz_intern" rows="2" maxlength="2000"><?= Util::h((string) ($kopf['notiz_intern'] ?? '')) ?></textarea></div>
        </div>
      </div>
      <div class="stapel">
      <?= karteAuf('Abschließen') ?>
        <div class="karte__koerper stapel stapel--eng">
          <button class="btn" type="submit" name="aktion" value="speichern"><?= Icon::svg('save', 15) ?> Entwurf speichern</button>
          <button class="btn btn--primaer" type="submit" name="aktion" value="ausstellen"<?= $luecken !== [] ? ' disabled' : '' ?>
                  data-bestaetigen="Jetzt ausstellen? Die Rechnung bekommt eine fortlaufende Nummer und lässt sich danach nicht mehr ändern – nur noch stornieren.">
            <?= Icon::svg('check', 15) ?> Ausstellen</button>
          <p class="klein gedimmt mb-0">Ausstellen vergibt die nächste Nummer, legt das PDF unveränderlich ab und macht die Rechnung
            in der Instanz sichtbar. Versendet wird danach in einem eigenen Schritt.</p>
        </div>
      </div>
      <?php if ($r !== null): ?>
        <button class="btn btn--geist btn--gefahr-text" type="submit" name="aktion" value="loeschen" formnovalidate
                data-bestaetigen="Diesen Entwurf löschen?"><?= Icon::svg('trash', 15) ?> Entwurf löschen</button>
      <?php endif; ?>
      </div>
    </div>
  </form>

<?php else:
  $bezug = (int) $r['bezug_id'] > 0 ? Betreiberrechnungen::finden((int) $r['bezug_id']) : null;
  $storno = DB::one("SELECT id, nummer FROM betreiber_rechnungen WHERE bezug_id = :i AND art = 'storno'", ['i' => $id]);
  $datei = Betreiberrechnungen::dateiInhalt($r) !== null; ?>
  <div class="raster raster--haupt-neben">
    <div class="stapel">
      <?= karteAuf('Positionen') ?>
        <div class="tabelle-huelle"><table class="tabelle tabelle--eng">
          <thead><tr><th>Pos.</th><th>Beschreibung</th><th class="zahl">Menge</th><th class="zahl">Einzelpreis</th><th>USt</th><th class="zahl">Betrag</th></tr></thead>
          <tbody>
            <?php foreach ($positionen as $p): ?>
              <tr><td><?= (int) $p['pos'] ?></td><td><?= nl2br(Util::h((string) $p['text'])) ?></td>
                <td class="zahl"><?= Util::h(rtrim(rtrim(number_format((int) $p['menge_hundertstel'] / 100, 2, ',', ''), '0'), ',') . ' ' . $p['einheit']) ?></td>
                <td class="zahl"><?= Util::h(Util::geld((int) $p['einzel_cent'])) ?></td><td><?= (int) $p['steuersatz'] ?> %</td>
                <td class="zahl"><?= Util::h(Util::geld((int) $p['netto_cent'])) ?></td></tr>
            <?php endforeach; ?>
          </tbody>
        </table></div>
        <div class="karte__koerper">
          <dl class="angaben angaben--summen">
            <?= angabe('Netto', Util::h(Util::geld((int) $r['netto_cent']))) ?>
            <?php foreach (Util::ausJson((string) $r['steuern'], []) as $s => $w): if ((int) $s === 0) { continue; } ?>
              <?= angabe('USt ' . (int) $s . ' %', Util::h(Util::geld((int) $w['steuer']))) ?>
            <?php endforeach; ?>
            <?= angabe('Gesamt', '<b>' . Util::h(Util::geld((int) $r['brutto_cent'])) . '</b>') ?>
          </dl>
          <?php if (Betreiberrechnungen::hinweis((string) $r['steuerfall']) !== ''): ?>
            <p class="klein gedimmt mt-3 mb-0"><?= Util::h(Betreiberrechnungen::hinweis((string) $r['steuerfall'])) ?></p>
          <?php endif; ?>
        </div>
      </div>
      <?= karteAuf('Interne Notiz', '<span class="pille pille--offen">nur Betreiber</span>') ?>
        <form class="karte__koerper" method="post" action="<?= Util::attr(App::url('/master/rechnung.php')) ?>">
          <?= Auth::csrfFeld() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="aktion" value="notiz_intern">
          <textarea class="eingabe" name="notiz_intern" rows="2" maxlength="2000" aria-label="Interne Notiz"><?= Util::h((string) $r['notiz_intern']) ?></textarea>
          <button class="btn btn--klein mt-2" type="submit">Speichern</button>
        </form>
      </div>
    </div>

    <div class="stapel">
      <?= karteAuf('Angaben') ?>
        <div class="karte__koerper">
          <dl class="angaben">
            <?= angabe('Rechnungsdatum', Util::h(Util::datum((string) $r['datum']))) ?>
            <?= angabe('Leistung', Util::h(Betreiberrechnungen::zeitraum($r))) ?>
            <?php if ((string) $r['art'] !== 'storno'): ?><?= angabe('Fällig am', Util::h(Util::datum((string) $r['faellig']))) ?><?php endif; ?>
            <?= angabe('Steuer', Util::h(Betreiberrechnungen::STEUERFALL[$r['steuerfall']] ?? (string) $r['steuerfall'])) ?>
            <?= angabe('Versendet', $r['versendet'] ? Util::h(Util::datumZeit((string) $r['versendet'])) : '<span class="gedimmt">noch nicht</span>') ?>
            <?= angabe('Bezahlt', $r['bezahlt'] ? Util::h(Util::datum((string) $r['bezahlt'])) : '<span class="gedimmt">—</span>') ?>
            <?php if ($bezug !== null): ?><?= angabe('Storniert', '<a href="' . Util::attr(App::url('/master/rechnung.php?id=' . (int) $bezug['id'])) . '">' . Util::h((string) $bezug['nummer']) . '</a>') ?><?php endif; ?>
            <?php if ($storno !== null): ?><?= angabe('Storno', '<a href="' . Util::attr(App::url('/master/rechnung.php?id=' . (int) $storno['id'])) . '">' . Util::h((string) $storno['nummer']) . '</a>') ?><?php endif; ?>
            <?= angabe('Ablage', $datei ? '<span class="pille pille--erfolg">unverändert</span>' : '<span class="pille pille--gefahr">Datei fehlt oder verändert</span>') ?>
          </dl>
        </div>
      </div>
      <?= karteAuf('Empfänger') ?>
        <div class="karte__koerper klein">
          <?= nl2br(Util::h(implode("\n", array_filter([(string) ($emp['firma'] ?? ''), (string) ($emp['name'] ?? ''), (string) ($emp['strasse'] ?? ''),
              trim(($emp['plz'] ?? '') . ' ' . ($emp['ort'] ?? '')), (string) ($emp['land'] ?? ''), (string) ($emp['email'] ?? ''),
              ($emp['ust_id'] ?? '') !== '' ? 'USt-IdNr. ' . $emp['ust_id'] : ''])))) ?>
        </div>
      </div>
      <?= karteAuf('Aktionen') ?>
        <div class="karte__koerper stapel stapel--eng">
          <form method="post" action="<?= Util::attr(App::url('/master/rechnung.php')) ?>">
            <?= Auth::csrfFeld() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="aktion" value="senden">
            <button class="btn btn--voll" type="submit" data-bestaetigen="An <?= Util::attr((string) ($emp['email'] ?? '—')) ?> schicken, mit dem PDF als Anhang?"
                    <?= ($emp['email'] ?? '') === '' ? 'disabled' : '' ?>><?= Icon::svg('send', 15) ?> Per E-Mail senden</button>
          </form>
          <?php if ((string) $r['status'] === 'offen'): ?>
            <button class="btn btn--primaer btn--voll" data-modal-auf="modal-bezahlt"><?= Icon::svg('check', 15) ?> Als bezahlt markieren</button>
          <?php elseif ((string) $r['status'] === 'bezahlt'): ?>
            <form method="post" action="<?= Util::attr(App::url('/master/rechnung.php')) ?>">
              <?= Auth::csrfFeld() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="aktion" value="offen">
              <button class="btn btn--voll" type="submit" data-bestaetigen="Zahlungseingang zurücknehmen?">Zahlung zurücknehmen</button>
            </form>
          <?php endif; ?>
          <?php if ((string) $r['art'] === 'rechnung' && in_array((string) $r['status'], ['offen', 'bezahlt'], true)): ?>
            <button class="btn btn--voll btn--gefahr-text" data-modal-auf="modal-storno"><?= Icon::svg('x', 15) ?> Stornieren</button>
          <?php endif; ?>
          <p class="klein gedimmt mb-0">Ausgestellt am <?= Util::h(Util::datumZeit((string) $r['ausgestellt'])) ?>. Ändern lässt sich die
            Rechnung nicht mehr – eine Korrektur ist eine Stornorechnung und eine neue Rechnung.</p>
        </div>
      </div>
    </div>
  </div>

  <dialog class="modal" id="modal-bezahlt">
    <form method="post" action="<?= Util::attr(App::url('/master/rechnung.php')) ?>">
      <?= Auth::csrfFeld() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="aktion" value="bezahlt">
      <div class="modal__kopf"><h2>Zahlungseingang</h2><button type="button" class="rundknopf" data-modal-zu aria-label="Schließen"><?= Icon::svg('x', 17) ?></button></div>
      <div class="modal__koerper"><div class="feld"><label class="feld__label" for="b-am">Eingegangen am</label>
        <input class="eingabe" id="b-am" type="date" name="am" value="<?= date('Y-m-d') ?>" required></div></div>
      <div class="modal__fuss"><button type="button" class="btn" data-modal-zu>Abbrechen</button><button class="btn btn--primaer" type="submit">Vermerken</button></div>
    </form>
  </dialog>
  <dialog class="modal" id="modal-storno">
    <form method="post" action="<?= Util::attr(App::url('/master/rechnung.php')) ?>">
      <?= Auth::csrfFeld() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="aktion" value="stornieren">
      <div class="modal__kopf"><h2>Rechnung stornieren</h2><button type="button" class="rundknopf" data-modal-zu aria-label="Schließen"><?= Icon::svg('x', 17) ?></button></div>
      <div class="modal__koerper">
        <p>Es entsteht eine <b>Stornorechnung</b> mit eigener Nummer und negativen Beträgen; diese Rechnung wird als storniert markiert.
          Beide bleiben erhalten. Eine korrigierte Rechnung legst du danach neu an.</p>
        <div class="feld"><label class="feld__label" for="s-grund">Grund (steht auf der Stornorechnung)</label>
          <input class="eingabe" id="s-grund" name="grund" maxlength="500" required placeholder="z. B. falscher Leistungszeitraum"></div>
      </div>
      <div class="modal__fuss"><button type="button" class="btn" data-modal-zu>Abbrechen</button><button class="btn btn--gefahr" type="submit">Stornorechnung ausstellen</button></div>
    </form>
  </dialog>
<?php endif; ?>

<?php require __DIR__ . '/partials/fuss.php';
