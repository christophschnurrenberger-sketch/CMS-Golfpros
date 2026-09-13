<?php
/** Gutscheine ausstellen, einlösen, als PDF drucken. */
require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/partials/helfer.php';

Auth::fordern('modul.products');

if (App::istPost()) {
    Auth::csrfFordern();
    Auth::fordern('products.write');

    if (App::aktion() === 'neu') {
        $id = Vouchers::erzeugen([
            'art' => App::post('art', 'wert'),
            'wert_cent' => Util::centAus(App::post('wert')),
            'leistung' => App::post('leistung'),
            'kaeufer' => App::post('kaeufer'),
            'empfaenger' => App::post('empfaenger'),
            'nachricht' => App::post('nachricht'),
            'customer_id' => App::postInt('customer_id'),
            'gueltig_bis' => App::post('gueltig_bis') ?: date('Y-m-d', strtotime('+3 years')),
        ]);
        App::melden('Gutschein erstellt. Das PDF kannst du sofort drucken oder verschicken.');
        App::weiter('/app/gutscheine.php?id=' . $id);
    }

    if (App::aktion() === 'einloesen') {
        [$ok, $meldung] = Vouchers::einloesen(App::post('code'),
            Util::centAus(App::post('betrag')), App::postInt('customer_id'));
        App::melden($meldung, $ok ? 'erfolg' : 'fehler');
    }

    if (App::aktion() === 'storno') {
        Tenant::update('vouchers', App::postInt('id'), ['status' => 'storniert']);
        App::melden('Gutschein storniert.');
    }
    App::weiter('/app/gutscheine.php');
}

$status = App::get('status', 'aktiv');
$gutscheine = Tenant::all('vouchers', $status !== 'alle' ? 'status = :s' : '',
    $status !== 'alle' ? ['s' => $status] : [], 'id DESC', 100);

$offenerWert = Tenant::sum('vouchers', 'rest_cent', 'status = "aktiv"');
$hervorgehoben = App::getInt('id') > 0 ? Tenant::find('vouchers', App::getInt('id')) : null;

$titel = 'Gutscheine';
$unter = Util::geld($offenerWert) . ' offenes Guthaben · '
       . Tenant::count('vouchers', "status = 'aktiv'") . ' gültige Gutscheine';
$brotkrumen = [['Produkte', '/app/produkte.php'], ['Gutscheine', null]];
$aktionen = Auth::darf('products.write')
    ? '<button class="btn" data-modal-auf="modal-einloesen">' . Icon::svg('check', 15) . ' Einlösen</button>'
      . '<button class="btn btn--primaer" data-modal-auf="modal-gutschein">' . Icon::svg('plus', 15) . ' Gutschein ausstellen</button>' : '';
require __DIR__ . '/partials/kopf.php';
?>

<?php if ($hervorgehoben): ?>
  <div class="karte mb-5" style="border-color:var(--marke)">
    <div class="karte__koerper">
      <div class="reihe reihe--umbruch" style="gap:var(--r6)">
        <div style="flex:1;min-width:240px">
          <div class="versal gedimmt-2 mb-2">Neuer Gutschein</div>
          <div style="font-size:30px;font-weight:680;letter-spacing:-.03em;color:var(--marke)">
            <?= (string) $hervorgehoben['art'] === 'wert'
                ? Util::h(Util::geldKurz((int) $hervorgehoben['wert_cent']))
                : Util::h((string) $hervorgehoben['leistung']) ?>
          </div>
          <div class="mono halbfett mt-3" style="font-size:17px"><?= Util::h((string) $hervorgehoben['code']) ?></div>
          <div class="klein gedimmt mt-2">Gültig bis <?= Util::h(Util::datum((string) $hervorgehoben['gueltig_bis'])) ?></div>
          <div class="reihe reihe--eng mt-4">
            <a class="btn btn--primaer" target="_blank" rel="noopener"
               href="<?= Util::attr(App::url('/app/pdf.php?art=gutschein&id=' . (int) $hervorgehoben['id'])) ?>">
              <?= Icon::svg('download', 15) ?> PDF öffnen</a>
            <button class="btn" data-kopieren="<?= Util::attr((string) $hervorgehoben['code']) ?>">
              <?= Icon::svg('copy', 15) ?> Code kopieren</button>
          </div>
        </div>
        <div><?= Vouchers::codeBild((string) $hervorgehoben['code'], 120) ?></div>
      </div>
    </div>
  </div>
<?php endif; ?>

<div class="reiter">
  <?php foreach (['aktiv' => 'Gültig', 'eingeloest' => 'Eingelöst', 'abgelaufen' => 'Abgelaufen',
                  'alle' => 'Alle'] as $k => $v): ?>
    <a class="reiter__teil<?= $status === $k ? ' ist-aktiv' : '' ?>"
       href="<?= Util::attr(App::url('/app/gutscheine.php?status=' . $k)) ?>"><?= Util::h($v) ?></a>
  <?php endforeach; ?>
</div>

<div class="karte">
  <?php if ($gutscheine === []): ?>
    <div class="karte__koerper">
      <?= leerzustand('gift', 'Keine Gutscheine in dieser Auswahl',
            'Gutscheine sind das einfachste Weihnachtsgeschäft eines Golf Professionals: '
            . 'Wertgutscheine für einen Betrag, Leistungsgutscheine für eine bestimmte Stunde. '
            . 'Das PDF ist sofort druckfertig.', []) ?>
    </div>
  <?php else: ?>
    <div class="tabelle-huelle">
      <table class="tabelle">
        <thead><tr><th>Code</th><th>Art</th><th class="zahl">Wert</th><th class="zahl">Rest</th>
          <th class="nicht-mobil">Für</th><th class="nicht-mobil">Gültig bis</th><th>Status</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($gutscheine as $g): ?>
          <tr>
            <td class="mono halbfett"><?= Util::h((string) $g['code']) ?></td>
            <td><?= Util::h((string) $g['art'] === 'wert' ? 'Wert' : 'Leistung') ?>
              <?php if ((string) $g['leistung'] !== ''): ?>
                <div class="winzig gedimmt-2"><?= Util::h((string) $g['leistung']) ?></div>
              <?php endif; ?>
            </td>
            <td class="zahl tabnum"><?= Util::h(Util::geldKurz((int) $g['wert_cent'])) ?></td>
            <td class="zahl tabnum halbfett"><?= Util::h(Util::geldKurz((int) $g['rest_cent'])) ?></td>
            <td class="nicht-mobil gedimmt"><?= Util::h((string) ($g['empfaenger'] ?: '—')) ?></td>
            <td class="nicht-mobil gedimmt"><?= Util::h(Util::datum((string) $g['gueltig_bis'])) ?></td>
            <td><?= pille((string) $g['status'], Vouchers::statusFarbe((string) $g['status'])) ?></td>
            <td class="aktionen">
              <a class="btn btn--klein" target="_blank" rel="noopener"
                 href="<?= Util::attr(App::url('/app/pdf.php?art=gutschein&id=' . (int) $g['id'])) ?>"
                 aria-label="PDF"><?= Icon::svg('download', 14) ?></a>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<?php if (Auth::darf('products.write')): ?>
<dialog class="modal" id="modal-gutschein">
  <form method="post">
    <?= Auth::csrfFeld() ?>
    <input type="hidden" name="aktion" value="neu">
    <div class="modal__kopf"><h2>Gutschein ausstellen</h2>
      <button type="button" class="rundknopf" data-modal-zu aria-label="Schließen"><?= Icon::svg('x', 17) ?></button></div>
    <div class="modal__koerper">
      <div class="feld-reihe feld-reihe--2">
        <div class="feld"><label class="feld__label" for="g-art">Art</label>
          <select id="g-art" name="art">
            <option value="wert">Wertgutschein</option>
            <option value="leistung">Leistungsgutschein</option>
          </select></div>
        <div class="feld"><label class="feld__label" for="g-wert">Betrag</label>
          <div class="eingabe-gruppe">
            <input class="eingabe" id="g-wert" name="wert" value="100,00">
            <span class="eingabe-gruppe__anhang"><?= Util::h(Tenant::waehrung()) ?></span></div></div>
      </div>
      <div class="feld"><label class="feld__label" for="g-leistung">Leistung (bei Leistungsgutschein)</label>
        <input class="eingabe" id="g-leistung" name="leistung" placeholder="Eine Schnupperstunde 60 Minuten"></div>
      <div class="feld-reihe feld-reihe--2">
        <div class="feld"><label class="feld__label" for="g-kaeufer">Gekauft von</label>
          <input class="eingabe" id="g-kaeufer" name="kaeufer"></div>
        <div class="feld"><label class="feld__label" for="g-empfaenger">Für</label>
          <input class="eingabe" id="g-empfaenger" name="empfaenger"></div>
      </div>
      <div class="feld"><label class="feld__label" for="g-nachricht">Persönliche Nachricht</label>
        <textarea class="eingabe" id="g-nachricht" name="nachricht" rows="2" data-waechst
                  placeholder="Alles Gute zum Geburtstag – viel Freude beim Training!"></textarea></div>
      <div class="feld"><label class="feld__label" for="g-bis">Gültig bis</label>
        <input class="eingabe" id="g-bis" type="date" name="gueltig_bis"
               value="<?= Util::attr(date('Y-m-d', strtotime('+3 years'))) ?>">
        <div class="feld__hinweis">Drei Jahre sind üblich – die gesetzliche Verjährungsfrist beträgt
          drei Jahre zum Jahresende.</div></div>
    </div>
    <div class="modal__fuss"><div class="fueller"></div>
      <button type="button" class="btn" data-modal-zu>Abbrechen</button>
      <button type="submit" class="btn btn--primaer">Ausstellen</button></div>
  </form>
</dialog>

<dialog class="modal modal--schmal" id="modal-einloesen">
  <form method="post">
    <?= Auth::csrfFeld() ?>
    <input type="hidden" name="aktion" value="einloesen">
    <div class="modal__kopf"><h2>Gutschein einlösen</h2>
      <button type="button" class="rundknopf" data-modal-zu aria-label="Schließen"><?= Icon::svg('x', 17) ?></button></div>
    <div class="modal__koerper">
      <div class="feld"><label class="feld__label" for="e-code">Code</label>
        <input class="eingabe mono" id="e-code" name="code" required autofocus placeholder="GP-XXXX-XXXX"
               style="text-transform:uppercase"></div>
      <div class="feld"><label class="feld__label" for="e-betrag">Einzulösender Betrag</label>
        <div class="eingabe-gruppe">
          <input class="eingabe" id="e-betrag" name="betrag" placeholder="leer = ganzer Rest">
          <span class="eingabe-gruppe__anhang"><?= Util::h(Tenant::waehrung()) ?></span></div>
        <div class="feld__hinweis">Bei Teileinlösung bleibt der Rest als Guthaben stehen.</div></div>
      <div class="feld"><label class="feld__label" for="e-kunde">Auf Kunde buchen</label>
        <select id="e-kunde" name="customer_id">
          <option value="0">Ohne Zuordnung</option>
          <?php foreach (Tenant::all('customers', "status = 'aktiv'", [], 'nachname') as $k): ?>
            <option value="<?= (int) $k['id'] ?>"><?= Util::h(Customers::name($k)) ?></option>
          <?php endforeach; ?>
        </select></div>
    </div>
    <div class="modal__fuss"><div class="fueller"></div>
      <button type="button" class="btn" data-modal-zu>Abbrechen</button>
      <button type="submit" class="btn btn--primaer">Einlösen</button></div>
  </form>
</dialog>
<?php endif; ?>

<?php require __DIR__ . '/partials/fuss.php'; ?>
