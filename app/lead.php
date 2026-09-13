<?php
/** Einzelne Anfrage: Verlauf, Notizen, Umwandlung in einen Kunden. */
require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/partials/helfer.php';

Auth::fordern('modul.leads');

$istNeu = App::get('id') === 'neu';
$id   = $istNeu ? 0 : App::getInt('id');
$lead = $istNeu ? null : Tenant::find('leads', $id);

if (!$istNeu && !$lead) {
    App::melden('Diese Anfrage wurde nicht gefunden.', 'fehler');
    App::weiter('/app/leads.php');
}

if (App::istPost()) {
    Auth::csrfFordern();
    Auth::fordern('leads.write');
    $aktion = App::aktion();

    if ($aktion === 'speichern') {
        $daten = [
            'name' => App::post('name'), 'email' => App::post('email'),
            'telefon' => App::post('telefon'), 'quelle' => App::post('quelle'),
            'stufe' => App::post('stufe', 'neu'), 'interesse' => App::post('interesse'),
            'wert_cent' => Util::centAus(App::post('wert')), 'notiz' => App::post('notiz'),
            'user_id' => App::postInt('user_id'),
        ];
        $neueId = Leads::speichern($daten, $id);
        App::melden($id > 0 ? 'Anfrage gespeichert.' : 'Anfrage erfasst.');
        App::weiter('/app/lead.php?id=' . $neueId);
    }

    if ($aktion === 'kontaktiert' && $id > 0) {
        Leads::kontaktiert($id);
        Leads::stufeSetzen($id, 'kontaktiert');
        Tenant::insert('communications', [
            'lead_id' => $id, 'kanal' => App::post('kanal', 'telefon'), 'richtung' => 'aus',
            'betreff' => 'Kontaktaufnahme', 'text' => App::post('text'), 'user_id' => Auth::id(),
        ]);
        App::melden('Kontakt vermerkt.');
        App::weiter('/app/lead.php?id=' . $id);
    }

    if ($aktion === 'zu_kunde' && $id > 0) {
        $kundeId = Leads::zuKunde($id);
        App::melden('Aus der Anfrage ist ein Kunde geworden.');
        App::weiter('/app/kunde.php?id=' . $kundeId);
    }

    if ($aktion === 'loeschen' && $id > 0) {
        Tenant::delete('leads', $id);
        App::melden('Anfrage gelöscht.');
        App::weiter('/app/leads.php');
    }
}

if ($istNeu) {
    $titel = 'Anfrage erfassen';
    $brotkrumen = [['Leads', '/app/leads.php'], ['Neu', null]];
    $lead = ['name' => '', 'email' => '', 'telefon' => '', 'quelle' => 'Telefon', 'stufe' => 'neu',
             'interesse' => '', 'wert_cent' => 0, 'notiz' => '', 'nachricht' => '',
             'user_id' => Auth::id(), 'erstellt' => Util::jetzt(), 'letzter_kontakt' => null,
             'customer_id' => 0, 'id' => 0];
} else {
    $titel = (string) $lead['name'];
    $unter = Leads::stufeName((string) $lead['stufe']) . ' · Eingegangen '
           . Util::relativ((string) $lead['erstellt'])
           . ((string) $lead['quelle'] !== '' ? ' über ' . $lead['quelle'] : '');
    $brotkrumen = [['Leads', '/app/leads.php'], [(string) $lead['name'], null]];
}

$verlauf = $id > 0 ? Tenant::all('communications', 'lead_id = :l', ['l' => $id], 'id DESC') : [];

$aktionen = '';
if (!$istNeu && Auth::darf('leads.write')) {
    if ((int) $lead['customer_id'] === 0) {
        $aktionen .= '<button class="btn btn--primaer" data-modal-auf="modal-kunde">'
                   . Icon::svg('user-plus', 15) . ' Zu Kunde machen</button>';
    } else {
        $aktionen .= '<a class="btn btn--primaer" href="' . App::url('/app/kunde.php?id=' . (int) $lead['customer_id']) . '">'
                   . Icon::svg('user', 15) . ' Zur Kundenakte</a>';
    }
}
require __DIR__ . '/partials/kopf.php';
?>

<div class="raster raster--haupt-neben">
  <div class="stapel">
    <?php if (!$istNeu && (string) $lead['nachricht'] !== ''): ?>
      <div class="karte">
        <div class="karte__kopf"><h3>Die Anfrage</h3>
          <div class="fueller"></div>
          <span class="klein gedimmt"><?= Util::h(Util::datumZeit((string) $lead['erstellt'])) ?></span></div>
        <div class="karte__koerper">
          <div style="font-size:14px;line-height:1.65"><?= nl2br(Util::h((string) $lead['nachricht'])) ?></div>
        </div>
      </div>
    <?php endif; ?>

    <form method="post" class="karte">
      <?= Auth::csrfFeld() ?>
      <input type="hidden" name="aktion" value="speichern">
      <div class="karte__kopf"><h2>Angaben</h2></div>
      <div class="karte__koerper">
        <div class="feld-reihe feld-reihe--2">
          <div class="feld"><label class="feld__label" for="name">Name</label>
            <input class="eingabe" id="name" name="name" value="<?= Util::attr((string) $lead['name']) ?>" required></div>
          <div class="feld"><label class="feld__label" for="stufe">Stufe</label>
            <select id="stufe" name="stufe">
              <?php foreach (Leads::STUFEN as $k => [$n, $f]): ?>
                <option value="<?= $k ?>"<?= (string) $lead['stufe'] === $k ? ' selected' : '' ?>><?= Util::h($n) ?></option>
              <?php endforeach; ?>
            </select></div>
        </div>
        <div class="feld-reihe feld-reihe--2">
          <div class="feld"><label class="feld__label" for="email">E-Mail</label>
            <input class="eingabe" id="email" type="email" name="email" value="<?= Util::attr((string) $lead['email']) ?>"></div>
          <div class="feld"><label class="feld__label" for="telefon">Telefon</label>
            <input class="eingabe" id="telefon" type="tel" name="telefon" value="<?= Util::attr((string) $lead['telefon']) ?>"></div>
        </div>
        <div class="feld-reihe feld-reihe--3">
          <div class="feld"><label class="feld__label" for="interesse">Interesse</label>
            <input class="eingabe" id="interesse" name="interesse" value="<?= Util::attr((string) $lead['interesse']) ?>"></div>
          <div class="feld"><label class="feld__label" for="quelle">Quelle</label>
            <input class="eingabe" id="quelle" name="quelle" value="<?= Util::attr((string) $lead['quelle']) ?>"></div>
          <div class="feld"><label class="feld__label" for="wert">Erwarteter Wert</label>
            <div class="eingabe-gruppe">
              <input class="eingabe" id="wert" name="wert"
                     value="<?= Util::attr(number_format((int) $lead['wert_cent'] / 100, 2, ',', '')) ?>">
              <span class="eingabe-gruppe__anhang"><?= Util::h(Tenant::waehrung()) ?></span>
            </div></div>
        </div>
        <div class="feld"><label class="feld__label" for="notiz">Notiz</label>
          <textarea class="eingabe" id="notiz" name="notiz" rows="4" data-waechst><?= Util::h((string) $lead['notiz']) ?></textarea></div>
        <?php if (count(Auth::trainer()) > 1): ?>
        <div class="feld"><label class="feld__label" for="user_id">Zuständig</label>
          <select id="user_id" name="user_id">
            <?php foreach (Auth::trainer() as $t): ?>
              <option value="<?= (int) $t['id'] ?>"<?= (int) $lead['user_id'] === (int) $t['id'] ? ' selected' : '' ?>>
                <?= Util::h((string) $t['name']) ?></option>
            <?php endforeach; ?>
          </select></div>
        <?php endif; ?>
      </div>
      <?php if (Auth::darf('leads.write')): ?>
      <div class="karte__fuss">
        <div class="fueller"></div>
        <button class="btn btn--primaer" type="submit">Speichern</button>
      </div>
      <?php endif; ?>
    </form>

    <?php if (!$istNeu): ?>
    <div class="karte">
      <div class="karte__kopf"><h3>Verlauf</h3>
        <div class="fueller"></div>
        <?php if (Auth::darf('leads.write')): ?>
          <button class="btn btn--klein" data-modal-auf="modal-kontakt">
            <?= Icon::svg('phone', 14) ?> Kontakt vermerken</button>
        <?php endif; ?>
      </div>
      <div class="karte__koerper">
        <?php if ($verlauf === []): ?>
          <p class="klein gedimmt mitte">Noch kein Kontakt vermerkt.</p>
        <?php else: ?>
          <div class="strahl">
            <?php foreach ($verlauf as $v): ?>
              <div class="strahl__punkt strahl__punkt--marke">
                <div class="strahl__zeit"><?= Util::h(Util::datumZeit((string) $v['erstellt'])) ?>
                  · <?= Util::h(Auth::trainerName((int) $v['user_id'])) ?></div>
                <div class="strahl__titel"><?= Util::h((string) $v['betreff']) ?>
                  <?= pille((string) $v['kanal'], 'offen') ?></div>
                <?php if ((string) $v['text'] !== ''): ?>
                  <div class="strahl__text"><?= nl2br(Util::h((string) $v['text'])) ?></div>
                <?php endif; ?>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>
  </div>

  <div class="stapel">
    <?php if (!$istNeu): ?>
    <div class="karte">
      <div class="karte__koerper">
        <div class="stapel stapel--eng">
          <?php if ((string) $lead['telefon'] !== ''): ?>
            <a class="btn btn--voll" href="tel:<?= Util::attr((string) $lead['telefon']) ?>">
              <?= Icon::svg('phone', 15) ?> <?= Util::h((string) $lead['telefon']) ?></a>
          <?php endif; ?>
          <?php if ((string) $lead['email'] !== ''): ?>
            <a class="btn btn--voll" href="mailto:<?= Util::attr((string) $lead['email']) ?>?subject=<?= rawurlencode('Deine Anfrage bei ' . Tenant::name()) ?>">
              <?= Icon::svg('mail', 15) ?> E-Mail schreiben</a>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <div class="karte">
      <div class="karte__kopf"><h3>Stufe ändern</h3></div>
      <div class="karte__koerper karte__koerper--eng">
        <div class="stapel stapel--eng">
          <?php foreach (Leads::STUFEN as $k => [$n, $f]): ?>
            <form method="post">
              <?= Auth::csrfFeld() ?>
              <input type="hidden" name="aktion" value="speichern">
              <input type="hidden" name="name" value="<?= Util::attr((string) $lead['name']) ?>">
              <input type="hidden" name="email" value="<?= Util::attr((string) $lead['email']) ?>">
              <input type="hidden" name="telefon" value="<?= Util::attr((string) $lead['telefon']) ?>">
              <input type="hidden" name="quelle" value="<?= Util::attr((string) $lead['quelle']) ?>">
              <input type="hidden" name="interesse" value="<?= Util::attr((string) $lead['interesse']) ?>">
              <input type="hidden" name="wert" value="<?= Util::attr(number_format((int) $lead['wert_cent'] / 100, 2, ',', '')) ?>">
              <input type="hidden" name="notiz" value="<?= Util::attr((string) $lead['notiz']) ?>">
              <input type="hidden" name="user_id" value="<?= (int) $lead['user_id'] ?>">
              <input type="hidden" name="stufe" value="<?= $k ?>">
              <button class="btn btn--voll<?= (string) $lead['stufe'] === $k ? ' btn--primaer' : '' ?>"
                      type="submit" style="justify-content:flex-start">
                <span style="width:8px;height:8px;border-radius:50%;background:<?= Util::attr($f) ?>"></span>
                <?= Util::h($n) ?>
              </button>
            </form>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

    <?php if (Auth::darf('leads.write')): ?>
    <form method="post" data-bestaetigen="Diese Anfrage löschen?">
      <?= Auth::csrfFeld() ?>
      <input type="hidden" name="aktion" value="loeschen">
      <button class="btn btn--voll" type="submit" style="color:var(--gefahr)">
        <?= Icon::svg('trash', 15) ?> Anfrage löschen</button>
    </form>
    <?php endif; ?>
    <?php endif; ?>
  </div>
</div>

<?php if (!$istNeu && Auth::darf('leads.write')): ?>
<dialog class="modal modal--schmal" id="modal-kontakt">
  <form method="post">
    <?= Auth::csrfFeld() ?>
    <input type="hidden" name="aktion" value="kontaktiert">
    <div class="modal__kopf"><h2>Kontakt vermerken</h2>
      <button type="button" class="rundknopf" data-modal-zu aria-label="Schließen"><?= Icon::svg('x', 17) ?></button></div>
    <div class="modal__koerper">
      <div class="feld"><label class="feld__label" for="k-kanal">Wie</label>
        <select id="k-kanal" name="kanal">
          <option value="telefon">Telefon</option><option value="email">E-Mail</option>
          <option value="whatsapp">WhatsApp</option><option value="notiz">Persönlich</option>
        </select></div>
      <div class="feld"><label class="feld__label" for="k-text">Was wurde besprochen?</label>
        <textarea class="eingabe" id="k-text" name="text" rows="4" data-waechst autofocus></textarea></div>
    </div>
    <div class="modal__fuss"><div class="fueller"></div>
      <button type="button" class="btn" data-modal-zu>Abbrechen</button>
      <button type="submit" class="btn btn--primaer">Vermerken</button></div>
  </form>
</dialog>

<dialog class="modal modal--schmal" id="modal-kunde">
  <form method="post">
    <?= Auth::csrfFeld() ?>
    <input type="hidden" name="aktion" value="zu_kunde">
    <div class="modal__kopf"><h2>Zu Kunde machen</h2></div>
    <div class="modal__koerper">
      <p>Aus <strong><?= Util::h((string) $lead['name']) ?></strong> wird ein Kunde mit eigener Akte.
        Die Anfrage bleibt als Herkunftsnachweis bestehen – so bleibt am Jahresende nachvollziehbar,
        welcher Kanal tatsächlich Kunden gebracht hat.</p>
    </div>
    <div class="modal__fuss"><div class="fueller"></div>
      <button type="button" class="btn" data-modal-zu>Abbrechen</button>
      <button type="submit" class="btn btn--primaer">Kunde anlegen</button></div>
  </form>
</dialog>
<?php endif; ?>

<?php require __DIR__ . '/partials/fuss.php'; ?>
