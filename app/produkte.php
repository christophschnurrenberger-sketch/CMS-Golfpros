<?php
/** Shop – was online gekauft werden kann. */
require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/partials/helfer.php';

Auth::fordern('modul.products');

if (App::istPost()) {
    Auth::csrfFordern();
    Auth::fordern('products.write');
    $id = App::postInt('id');

    if (App::aktion() === 'speichern') {
        $daten = [
            'art' => App::post('art', 'paket'), 'name' => App::post('name'),
            'slug' => Util::slug(App::post('name')),
            'kurztext' => App::post('kurztext'), 'beschreibung' => App::post('beschreibung'),
            'preis_cent' => Util::centAus(App::post('preis')),
            'vergleichspreis_cent' => Util::centAus(App::post('vergleichspreis')),
            'bestand' => App::post('bestand') === '' ? -1 : App::postInt('bestand'),
            'ref_id' => App::postInt('ref_id'),
            'aktiv' => App::postBool('aktiv') ? 1 : 0,
        ];
        if ($daten['name'] === '') {
            App::melden('Das Produkt braucht einen Namen.', 'fehler');
        } elseif ($id > 0) {
            Tenant::update('products', $id, $daten);
            App::melden('Produkt gespeichert.');
        } else {
            $daten['position'] = Tenant::count('products');
            Tenant::insert('products', $daten);
            App::melden('Produkt angelegt.');
        }
    }
    if (App::aktion() === 'rabatt') {
        Tenant::insert('discounts', [
            'code' => strtoupper(App::post('code')),
            'art' => App::post('rabatt_art', 'prozent'),
            'wert' => App::postInt('wert'),
            'mindestwert_cent' => Util::centAus(App::post('mindestwert')),
            'gueltig_bis' => App::post('gueltig_bis') !== '' ? App::post('gueltig_bis') . ' 23:59:59' : null,
            'limit_gesamt' => App::postInt('limit_gesamt'),
            'aktiv' => 1,
        ]);
        App::melden('Rabattcode angelegt.');
    }
    if (App::aktion() === 'loeschen' && $id > 0) {
        Tenant::update('products', $id, ['aktiv' => 0]);
        App::melden('Produkt deaktiviert – bestehende Bestellungen bleiben lesbar.', 'info');
    }
    App::weiter('/app/produkte.php');
}

$art = App::get('art');
$produkte = Tenant::all('products', $art !== '' ? 'art = :a' : '', $art !== '' ? ['a' => $art] : [],
    'aktiv DESC, position, id');
$rabatte = Tenant::all('discounts', '', [], 'aktiv DESC, id DESC');
$bestseller = Commerce::bestseller(5, 180);

$titel = 'Produkte';
$unter = count($produkte) . ' Produkte · ' . Tenant::count('orders', "status = 'bezahlt'") . ' Bestellungen';
$aktionen = Auth::darf('products.write')
    ? '<a class="btn" href="' . App::url('/app/gutscheine.php') . '">' . Icon::svg('gift', 15) . ' Gutscheine</a>'
      . '<button class="btn btn--primaer" data-modal-auf="modal-produkt" data-modal-titel="Neues Produkt">'
      . Icon::svg('plus', 15) . ' Produkt anlegen</button>' : '';
require __DIR__ . '/partials/kopf.php';
?>

<div class="reihe reihe--eng reihe--umbruch mb-4">
  <a class="pille <?= $art === '' ? 'pille--marke' : 'pille--offen' ?>"
     href="<?= Util::attr(App::url('/app/produkte.php')) ?>">Alle</a>
  <?php foreach (Commerce::PRODUKT_ARTEN as $k => [$name, $icon]):
    $n = Tenant::count('products', 'art = :a', ['a' => $k]);
    if ($n === 0) { continue; } ?>
    <a class="pille <?= $art === $k ? 'pille--marke' : 'pille--offen' ?>"
       href="<?= Util::attr(App::url('/app/produkte.php?art=' . $k)) ?>">
      <?= Util::h($name) ?> <span class="gedimmt-2"><?= $n ?></span></a>
  <?php endforeach; ?>
</div>

<div class="raster raster--haupt-neben">
  <div class="stapel">
    <?php if ($produkte === []): ?>
      <div class="karte"><div class="karte__koerper">
        <?= leerzustand('products', 'Noch kein Produkt',
              'Alles, was online verkauft werden soll, ist ein Produkt: Trainingspakete, '
              . 'Onlinekurse, Gutscheine, Workshop-Plätze oder Ausrüstung. Pakete legst du am '
              . 'einfachsten unter Pakete an – sie werden dort mit einem Haken verkaufbar.',
              [['Zu den Paketen', '/app/pakete.php', 'ticket']]) ?>
      </div></div>
    <?php else: ?>
      <div class="karte">
        <div class="tabelle-huelle">
          <table class="tabelle">
            <thead><tr><th>Produkt</th><th class="nicht-mobil">Art</th>
              <th class="zahl">Preis</th><th class="nicht-mobil">Bestand</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($produkte as $p): ?>
              <tr style="<?= (int) $p['aktiv'] === 0 ? 'opacity:.55' : '' ?>">
                <td>
                  <span class="haupt"><?= Util::h((string) $p['name']) ?></span>
                  <div class="winzig gedimmt-2"><?= Util::h(Util::kuerzen((string) $p['kurztext'], 60)) ?></div>
                </td>
                <td class="nicht-mobil">
                  <?= pille(Commerce::PRODUKT_ARTEN[(string) $p['art']][0] ?? (string) $p['art'], 'offen') ?></td>
                <td class="zahl tabnum">
                  <?= Util::h(Util::geldKurz((int) $p['preis_cent'])) ?>
                  <?php if ((int) $p['vergleichspreis_cent'] > (int) $p['preis_cent']): ?>
                    <div class="winzig gedimmt-2"><s><?= Util::h(Util::geldKurz((int) $p['vergleichspreis_cent'])) ?></s></div>
                  <?php endif; ?>
                </td>
                <td class="nicht-mobil">
                  <?= (int) $p['bestand'] < 0 ? '<span class="gedimmt-2">unbegrenzt</span>'
                      : ((int) $p['bestand'] === 0 ? pille('ausverkauft', 'gefahr') : (int) $p['bestand']) ?>
                </td>
                <td class="aktionen">
                  <?php if (Auth::darf('products.write')): ?>
                    <button class="btn btn--klein" data-modal-auf="modal-produkt"
                            data-setzid="<?= (int) $p['id'] ?>"
                            data-setzname="<?= Util::attr((string) $p['name']) ?>"
                            data-setzart="<?= Util::attr((string) $p['art']) ?>"
                            data-setzkurztext="<?= Util::attr((string) $p['kurztext']) ?>"
                            data-setzbeschreibung="<?= Util::attr((string) $p['beschreibung']) ?>"
                            data-setzpreis="<?= Util::attr(number_format((int) $p['preis_cent'] / 100, 2, ',', '')) ?>"
                            data-setzvergleichspreis="<?= Util::attr(number_format((int) $p['vergleichspreis_cent'] / 100, 2, ',', '')) ?>"
                            data-modal-titel="Produkt bearbeiten">
                      <?= Icon::svg('edit', 14) ?></button>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    <?php endif; ?>

    <div class="karte">
      <div class="karte__kopf"><h3>Rabattcodes</h3>
        <div class="fueller"></div>
        <?php if (Auth::darf('products.write')): ?>
          <button class="btn btn--klein btn--geist" data-modal-auf="modal-rabatt"><?= Icon::svg('plus', 14) ?></button>
        <?php endif; ?>
      </div>
      <div class="karte__koerper karte__koerper--eng">
        <?php if ($rabatte === []): ?>
          <p class="klein gedimmt mitte" style="padding:var(--r3) 0">Kein Rabattcode angelegt.</p>
        <?php else: ?>
          <div class="stapel stapel--eng">
            <?php foreach ($rabatte as $r):
              $abgelaufen = $r['gueltig_bis'] && $r['gueltig_bis'] < Util::jetzt(); ?>
              <div class="reihe" style="padding:6px;<?= $abgelaufen || (int) $r['aktiv'] === 0 ? 'opacity:.55' : '' ?>">
                <span class="mono halbfett"><?= Util::h((string) $r['code']) ?></span>
                <?= pille((string) $r['art'] === 'prozent'
                      ? (int) $r['wert'] . ' %' : Util::geldKurz((int) $r['wert']), 'marke') ?>
                <div class="fueller"></div>
                <span class="klein gedimmt">
                  <?= (int) $r['genutzt'] ?><?= (int) $r['limit_gesamt'] > 0 ? ' / ' . (int) $r['limit_gesamt'] : '' ?>
                  genutzt
                  <?php if ($r['gueltig_bis']): ?> · bis <?= Util::h(Util::datum((string) $r['gueltig_bis'])) ?><?php endif; ?>
                </span>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="stapel">
    <div class="karte">
      <div class="karte__kopf"><h3>Verkaufsschlager</h3>
        <div class="fueller"></div><span class="klein gedimmt">180 Tage</span></div>
      <div class="karte__koerper">
        <?php if ($bestseller === []): ?>
          <p class="klein gedimmt mitte">Noch keine Verkäufe.</p>
        <?php else:
          $max = max(array_map(static fn($b) => $b['umsatz'], $bestseller)); ?>
          <div class="rangliste">
            <?php foreach ($bestseller as $b): ?>
              <div class="rang">
                <span class="rang__name"><?= Util::h($b['titel']) ?></span>
                <span class="rang__wert"><?= Util::h(Util::geldKurz($b['umsatz'])) ?>
                  <span class="gedimmt klein"><?= $b['anzahl'] ?>×</span></span>
                <span class="rang__spur"><span class="rang__fuellung"
                      data-breite="<?= (int) round($b['umsatz'] / max(1, $max) * 100) ?>%"></span></span>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <?php if (!Stripe::eingerichtet()): ?>
      <div class="hinweis hinweis--warnung">
        <?= Icon::svg('alert', 17) ?>
        <div class="hinweis__text">
          <span class="hinweis__titel">Zahlungen laufen im Testmodus</span>
          Bestellungen werden angelegt und als bezahlt verbucht, aber es fließt kein Geld.
          Für echte Zahlungen den Stripe-Schlüssel in der <code>config.php</code> hinterlegen.
        </div>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php if (Auth::darf('products.write')): ?>
<dialog class="modal" id="modal-produkt">
  <form method="post">
    <?= Auth::csrfFeld() ?>
    <input type="hidden" name="aktion" value="speichern">
    <input type="hidden" name="id" value="0">
    <div class="modal__kopf"><h2>Produkt</h2>
      <button type="button" class="rundknopf" data-modal-zu aria-label="Schließen"><?= Icon::svg('x', 17) ?></button></div>
    <div class="modal__koerper">
      <div class="feld-reihe feld-reihe--2">
        <div class="feld"><label class="feld__label" for="pr-name">Name</label>
          <input class="eingabe" id="pr-name" name="name" required></div>
        <div class="feld"><label class="feld__label" for="pr-art">Art</label>
          <select id="pr-art" name="art">
            <?php foreach (Commerce::PRODUKT_ARTEN as $k => [$n, $i]): ?>
              <option value="<?= $k ?>"><?= Util::h($n) ?></option>
            <?php endforeach; ?>
          </select></div>
      </div>
      <div class="feld"><label class="feld__label" for="pr-kurz">Kurztext</label>
        <input class="eingabe" id="pr-kurz" name="kurztext" placeholder="5 Einheiten à 60 Minuten"></div>
      <div class="feld"><label class="feld__label" for="pr-text">Beschreibung</label>
        <textarea class="eingabe" id="pr-text" name="beschreibung" rows="4" data-waechst></textarea></div>
      <div class="feld-reihe feld-reihe--3">
        <div class="feld"><label class="feld__label" for="pr-preis">Preis</label>
          <div class="eingabe-gruppe">
            <input class="eingabe" id="pr-preis" name="preis" value="0,00">
            <span class="eingabe-gruppe__anhang"><?= Util::h(Tenant::waehrung()) ?></span></div></div>
        <div class="feld"><label class="feld__label" for="pr-vergleich">Statt-Preis</label>
          <div class="eingabe-gruppe">
            <input class="eingabe" id="pr-vergleich" name="vergleichspreis" value="0,00">
            <span class="eingabe-gruppe__anhang"><?= Util::h(Tenant::waehrung()) ?></span></div>
          <div class="feld__hinweis">0 = keiner</div></div>
        <div class="feld"><label class="feld__label" for="pr-bestand">Bestand</label>
          <input class="eingabe" id="pr-bestand" type="number" name="bestand" placeholder="leer = unbegrenzt"></div>
      </div>
      <label class="haken"><input type="checkbox" name="aktiv" value="1" checked>
        <span class="haken__text">Im Shop sichtbar</span></label>
    </div>
    <div class="modal__fuss"><div class="fueller"></div>
      <button type="button" class="btn" data-modal-zu>Abbrechen</button>
      <button type="submit" class="btn btn--primaer">Speichern</button></div>
  </form>
</dialog>

<dialog class="modal modal--schmal" id="modal-rabatt">
  <form method="post">
    <?= Auth::csrfFeld() ?>
    <input type="hidden" name="aktion" value="rabatt">
    <div class="modal__kopf"><h2>Rabattcode</h2>
      <button type="button" class="rundknopf" data-modal-zu aria-label="Schließen"><?= Icon::svg('x', 17) ?></button></div>
    <div class="modal__koerper">
      <div class="feld"><label class="feld__label" for="rb-code">Code</label>
        <input class="eingabe mono" id="rb-code" name="code" required placeholder="FRUEHLING26"
               style="text-transform:uppercase"></div>
      <div class="feld-reihe feld-reihe--2">
        <div class="feld"><label class="feld__label" for="rb-art">Art</label>
          <select id="rb-art" name="rabatt_art">
            <option value="prozent">Prozent</option>
            <option value="betrag">Fester Betrag (Cent)</option>
          </select></div>
        <div class="feld"><label class="feld__label" for="rb-wert">Wert</label>
          <input class="eingabe" id="rb-wert" type="number" name="wert" value="10" min="1"></div>
      </div>
      <div class="feld-reihe feld-reihe--2">
        <div class="feld"><label class="feld__label" for="rb-min">Mindestbestellwert</label>
          <input class="eingabe" id="rb-min" name="mindestwert" value="0,00"></div>
        <div class="feld"><label class="feld__label" for="rb-limit">Höchstens … Einlösungen</label>
          <input class="eingabe" id="rb-limit" type="number" name="limit_gesamt" value="0" min="0">
          <div class="feld__hinweis">0 = unbegrenzt</div></div>
      </div>
      <div class="feld"><label class="feld__label" for="rb-bis">Gültig bis</label>
        <input class="eingabe" id="rb-bis" type="date" name="gueltig_bis"></div>
    </div>
    <div class="modal__fuss"><div class="fueller"></div>
      <button type="button" class="btn" data-modal-zu>Abbrechen</button>
      <button type="submit" class="btn btn--primaer">Anlegen</button></div>
  </form>
</dialog>
<?php endif; ?>

<?php require __DIR__ . '/partials/fuss.php'; ?>
