<?php
/**
 * Pakete – die Vorlagen und das verkaufte Guthaben.
 *
 * Zwei Listen auf einer Seite, weil sie zusammengehören: links, was
 * angeboten wird, rechts, wer wie viel davon offen hat. Genau diese zweite
 * Liste ist die wertvollste im ganzen System – bezahlte Einheiten ohne
 * Termin sind verschenkter Umsatz.
 */
require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/partials/helfer.php';

Auth::fordern('modul.bookings');

if (App::istPost()) {
    Auth::csrfFordern();
    Auth::fordern('bookings.write');
    $id = App::postInt('id');

    if (App::aktion() === 'speichern') {
        $daten = [
            'name' => App::post('name'),
            'einheiten' => max(1, App::postInt('einheiten', 5)),
            'dauer_min' => max(5, App::postInt('dauer_min', 60)),
            'preis_cent' => Util::centAus(App::post('preis')),
            'gueltig_tage' => max(1, App::postInt('gueltig_tage', 365)),
            'beschreibung' => App::post('beschreibung'),
            'service_id' => App::postInt('service_id'),
            'aktiv' => App::postBool('aktiv') ? 1 : 0,
        ];
        if ($daten['name'] === '') {
            App::melden('Das Paket braucht einen Namen.', 'fehler');
        } elseif ($id > 0) {
            Tenant::update('packages', $id, $daten);
            App::melden('Paket gespeichert.');
        } else {
            $daten['position'] = Tenant::count('packages');
            $neuId = Tenant::insert('packages', $daten);
            /* Ein Paket ohne Produkt lässt sich nicht online verkaufen. */
            if (App::postBool('verkaufen')) {
                Tenant::insert('products', [
                    'art' => 'paket', 'name' => $daten['name'], 'slug' => Util::slug($daten['name']),
                    'kurztext' => $daten['einheiten'] . ' Einheiten à ' . $daten['dauer_min'] . ' Minuten',
                    'beschreibung' => $daten['beschreibung'], 'preis_cent' => $daten['preis_cent'],
                    'ref_id' => $neuId, 'aktiv' => 1,
                ]);
            }
            App::melden('Paket angelegt.');
        }
    }

    if (App::aktion() === 'storno' && $id > 0) {
        Tenant::update('customer_packages', $id, ['status' => 'storniert']);
        Audit::schreiben('storniert', 'customer_package', $id);
        App::melden('Guthaben storniert.');
    }
    App::weiter('/app/pakete.php');
}

$filter = App::get('filter');
$pakete = Tenant::all('packages', '', [], 'aktiv DESC, position, name');

$wo = 'status = "aktiv" AND einheiten_genutzt < einheiten_gesamt';
$p  = [];
$filterName = 'Offenes Guthaben';
if ($filter === 'laeuft_ab') {
    $wo .= ' AND laeuft_ab <= :grenze';
    $p['grenze'] = date('Y-m-d H:i:s', strtotime('+30 days'));
    $filterName = 'Läuft in 30 Tagen ab';
} elseif ($filter === 'ohne_termin') {
    $wo .= ' AND customer_id NOT IN (SELECT customer_id FROM bookings
            WHERE workspace_id = ' . Tenant::id() . ' AND start >= "' . Util::jetzt() . '" AND status != "abgesagt")';
    $filterName = 'Guthaben ohne Termin';
} elseif ($filter === 'alle') {
    $wo = '';
    $filterName = 'Alle Pakete';
}
$guthaben = Tenant::all('customer_packages', $wo, $p, 'laeuft_ab', 120);

$offeneEinheiten = Tenant::sum('customer_packages', 'einheiten_gesamt - einheiten_genutzt',
    'status = "aktiv" AND einheiten_genutzt < einheiten_gesamt');
$offenerWert = 0;
foreach (Tenant::all('customer_packages', "status = 'aktiv' AND einheiten_genutzt < einheiten_gesamt") as $cp) {
    $gesamtEinheiten = max(1, (int) $cp['einheiten_gesamt']);
    $offenerWert += (int) round((int) $cp['preis_cent'] / $gesamtEinheiten
                    * ((int) $cp['einheiten_gesamt'] - (int) $cp['einheiten_genutzt']));
}

$titel = 'Pakete';
$unter = Util::zahl($offeneEinheiten) . ' offene Einheiten im Wert von ' . Util::geld($offenerWert)
       . ' · noch nicht eingelöst';
$aktionen = Auth::darf('bookings.write')
    ? '<button class="btn btn--primaer" data-modal-auf="modal-paket">' . Icon::svg('plus', 15) . ' Paket anlegen</button>' : '';
require __DIR__ . '/partials/kopf.php';
?>

<div class="raster raster--2 mb-5">
  <?= kennzahl('Offene Einheiten', Util::zahl($offeneEinheiten), [
        'icon' => 'ticket', 'fuss' => 'aus verkauften Paketen']) ?>
  <?= kennzahl('Gebundener Wert', Util::geld($offenerWert), [
        'icon' => 'euro', 'fuss' => 'bereits bezahlt, noch nicht geleistet']) ?>
</div>

<div class="reiter">
  <?php foreach (['offen' => 'Offenes Guthaben', 'laeuft_ab' => 'Läuft bald ab',
                  'ohne_termin' => 'Ohne Termin', 'alle' => 'Alle'] as $k => $v): ?>
    <a class="reiter__teil<?= ($filter === $k || ($filter === '' && $k === 'offen')) ? ' ist-aktiv' : '' ?>"
       href="<?= Util::attr(App::url('/app/pakete.php?filter=' . $k)) ?>"><?= Util::h($v) ?></a>
  <?php endforeach; ?>
</div>

<div class="raster raster--haupt-neben">
  <div class="karte">
    <div class="karte__kopf"><h2><?= Util::h($filterName) ?></h2>
      <span class="pille"><?= count($guthaben) ?></span></div>
    <?php if ($guthaben === []): ?>
      <div class="karte__koerper">
        <?= leerzustand('ticket', 'Kein Guthaben in dieser Auswahl',
              'Sobald ein Kunde ein Paket kauft oder du ihm eines gutschreibst, steht es hier – '
              . 'mit Restguthaben und Ablaufdatum.', []) ?>
      </div>
    <?php else: ?>
      <div class="tabelle-huelle">
        <table class="tabelle">
          <thead><tr><th>Kunde</th><th>Paket</th><th>Verbrauch</th>
            <th class="nicht-mobil">Läuft ab</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($guthaben as $g):
            $rest = (int) $g['einheiten_gesamt'] - (int) $g['einheiten_genutzt'];
            $prozent = (int) $g['einheiten_gesamt'] > 0
              ? (int) round((int) $g['einheiten_genutzt'] / (int) $g['einheiten_gesamt'] * 100) : 0;
            $tage = Util::tageBis((string) $g['laeuft_ab']); ?>
            <tr>
              <td><?= person(Customers::nameVonId((int) $g['customer_id']),
                    ['url' => '/app/kunde.php?id=' . (int) $g['customer_id']]) ?></td>
              <td><?= Util::h((string) $g['name']) ?></td>
              <td style="min-width:150px">
                <div class="reihe reihe--eng klein mb-2">
                  <span class="halbfett tabnum"><?= $rest ?> von <?= (int) $g['einheiten_gesamt'] ?></span>
                </div>
                <?= balken($prozent, $rest <= 1 ? 'warnung' : '') ?>
              </td>
              <td class="nicht-mobil umbruch-nein">
                <?php if ($g['laeuft_ab']): ?>
                  <span class="<?= $tage <= 30 ? '' : 'gedimmt' ?>"
                        style="<?= $tage <= 30 ? 'color:var(--warnung);font-weight:560' : '' ?>">
                    <?= Util::h(Util::datum((string) $g['laeuft_ab'])) ?></span>
                  <div class="winzig gedimmt-2"><?= $tage > 0 ? 'in ' . $tage . ' Tagen' : 'abgelaufen' ?></div>
                <?php else: ?><span class="gedimmt-2">—</span><?php endif; ?>
              </td>
              <td class="aktionen">
                <?php if (Auth::darf('bookings.write')): ?>
                  <a class="btn btn--klein" href="<?= Util::attr(App::url('/app/buchung.php?id=neu&kunde=' . (int) $g['customer_id'])) ?>">
                    Termin</a>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

  <div class="stapel">
    <div class="karte">
      <div class="karte__kopf"><h3>Angebotene Pakete</h3></div>
      <div class="karte__koerper karte__koerper--eng">
        <?php if ($pakete === []): ?>
          <p class="klein gedimmt mitte" style="padding:var(--r4) 0">
            Noch kein Paket angelegt.<br>Pakete binden Kunden und bringen Umsatz im Voraus.</p>
        <?php else: ?>
          <div class="stapel stapel--eng">
            <?php foreach ($pakete as $pk):
              $verkauft = Tenant::count('customer_packages', 'package_id = :p', ['p' => (int) $pk['id']]); ?>
              <div class="kachel-link" style="<?= (int) $pk['aktiv'] === 0 ? 'opacity:.55' : '' ?>">
                <span class="kachel-link__symbol"><?= Icon::svg('ticket', 16) ?></span>
                <div style="flex:1;min-width:0">
                  <div class="kachel-link__titel"><?= Util::h((string) $pk['name']) ?></div>
                  <div class="kachel-link__text">
                    <?= (int) $pk['einheiten'] ?> × <?= (int) $pk['dauer_min'] ?> Min ·
                    <?= Util::h(Util::geldKurz((int) $pk['preis_cent'])) ?>
                    <?php if ($verkauft > 0): ?> · <?= $verkauft ?>× verkauft<?php endif; ?>
                  </div>
                </div>
                <?php if (Auth::darf('bookings.write')): ?>
                  <button class="btn btn--klein btn--geist btn--nur-symbol" data-modal-auf="modal-paket"
                          data-setzid="<?= (int) $pk['id'] ?>"
                          data-setzname="<?= Util::attr((string) $pk['name']) ?>"
                          data-setzeinheiten="<?= (int) $pk['einheiten'] ?>"
                          data-setzdauer_min="<?= (int) $pk['dauer_min'] ?>"
                          data-setzpreis="<?= Util::attr(number_format((int) $pk['preis_cent'] / 100, 2, ',', '')) ?>"
                          data-setzgueltig_tage="<?= (int) $pk['gueltig_tage'] ?>"
                          data-setzbeschreibung="<?= Util::attr((string) $pk['beschreibung']) ?>"
                          data-modal-titel="Paket bearbeiten" aria-label="Bearbeiten">
                    <?= Icon::svg('edit', 14) ?></button>
                <?php endif; ?>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <div class="hinweis hinweis--still">
      <?= Icon::svg('info', 17) ?>
      <div class="hinweis__text">
        <span class="hinweis__titel">Warum Pakete?</span>
        Sie bringen Umsatz im Voraus und binden Kunden über mehrere Monate. Wichtig ist die
        zweite Spalte: Wer Guthaben hat und keinen Termin, braucht einen Anruf – nicht ein Angebot.
      </div>
    </div>
  </div>
</div>

<?php if (Auth::darf('bookings.write')): ?>
<dialog class="modal" id="modal-paket">
  <form method="post">
    <?= Auth::csrfFeld() ?>
    <input type="hidden" name="aktion" value="speichern">
    <input type="hidden" name="id" value="0">
    <div class="modal__kopf"><h2>Paket</h2>
      <button type="button" class="rundknopf" data-modal-zu aria-label="Schließen"><?= Icon::svg('x', 17) ?></button></div>
    <div class="modal__koerper">
      <div class="feld"><label class="feld__label" for="pk-name">Name</label>
        <input class="eingabe" id="pk-name" name="name" required placeholder="Golf Starter 5"></div>
      <div class="feld-reihe feld-reihe--3">
        <div class="feld"><label class="feld__label" for="pk-einheiten">Einheiten</label>
          <input class="eingabe" id="pk-einheiten" type="number" name="einheiten" value="5" min="1"></div>
        <div class="feld"><label class="feld__label" for="pk-dauer">Dauer je Einheit</label>
          <input class="eingabe" id="pk-dauer" type="number" name="dauer_min" value="60" min="5" step="5"></div>
        <div class="feld"><label class="feld__label" for="pk-preis">Gesamtpreis</label>
          <div class="eingabe-gruppe">
            <input class="eingabe" id="pk-preis" name="preis" value="0,00">
            <span class="eingabe-gruppe__anhang"><?= Util::h(Tenant::waehrung()) ?></span>
          </div></div>
      </div>
      <div class="feld"><label class="feld__label" for="pk-gueltig">Gültig für (Tage)</label>
        <input class="eingabe" id="pk-gueltig" type="number" name="gueltig_tage" value="365" min="1">
        <div class="feld__hinweis">Ab Kaufdatum. Ein Jahr ist üblich und fair.</div></div>
      <div class="feld"><label class="feld__label" for="pk-text">Beschreibung</label>
        <textarea class="eingabe" id="pk-text" name="beschreibung" rows="3" data-waechst
                  placeholder="Für wen ist das Paket gedacht und was ist enthalten?"></textarea></div>
      <div class="feld-reihe feld-reihe--2">
        <label class="haken"><input type="checkbox" name="verkaufen" value="1" checked>
          <span class="haken__text">Auch online verkaufen
            <span class="haken__hinweis">Legt ein Produkt an, das auf der Website kaufbar ist.</span></span></label>
        <label class="haken"><input type="checkbox" name="aktiv" value="1" checked>
          <span class="haken__text">Aktiv</span></label>
      </div>
    </div>
    <div class="modal__fuss"><div class="fueller"></div>
      <button type="button" class="btn" data-modal-zu>Abbrechen</button>
      <button type="submit" class="btn btn--primaer">Speichern</button></div>
  </form>
</dialog>
<?php endif; ?>

<?php require __DIR__ . '/partials/fuss.php'; ?>
