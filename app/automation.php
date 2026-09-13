<?php
/**
 * Ablauf bearbeiten – als Kette von oben nach unten.
 *
 * Ein Ablaufdiagramm mit Verzweigungen sieht mächtiger aus, ist aber für
 * das, was ein Golf Professional braucht, unnötig kompliziert. Eine Kette
 * mit einer Bedingung, die abbrechen kann, deckt alle fünf Vorlagen ab.
 */
require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/partials/helfer.php';

Auth::fordern('modul.automations');

$id = App::getInt('id');
$automation = Tenant::find('automations', $id);
if (!$automation) {
    App::melden('Dieser Ablauf wurde nicht gefunden.', 'fehler');
    App::weiter('/app/automationen.php');
}

if (App::istPost()) {
    Auth::csrfFordern();
    Auth::fordern('automations.write');
    $aktion = App::aktion();

    if ($aktion === 'speichern') {
        Tenant::update('automations', $id, [
            'name' => App::post('name'), 'beschreibung' => App::post('beschreibung'),
            'ausloeser' => App::post('ausloeser'), 'status' => App::post('status'),
        ]);
        App::melden('Ablauf gespeichert.');
    }
    if ($aktion === 'schritt') {
        Automations::schrittHinzufuegen($id, App::post('art', 'email'), [
            'betreff' => App::post('betreff'), 'text' => App::postRoh('text'),
            'tage' => App::postInt('tage'), 'tag' => App::post('tag'),
            'titel' => App::post('titel'), 'segment_id' => App::postInt('segment_id'),
            'werblich' => App::postBool('werblich'),
        ]);
        App::melden('Schritt hinzugefügt.');
    }
    if ($aktion === 'schritt_weg') {
        Tenant::deleteWhere('automation_steps', 'id = :s AND automation_id = :a',
            ['s' => App::postInt('schritt_id'), 'a' => $id]);
    }
    App::weiter('/app/automation.php?id=' . $id);
}

$schritte = Automations::schritte($id);
$laeufe = Tenant::all('automation_runs', 'automation_id = :a', ['a' => $id], 'id DESC', 12);
$aktiv = (string) $automation['status'] === 'aktiv';

$titel = (string) $automation['name'];
$unter = 'Auslöser: ' . (Automations::AUSLOESER[(string) $automation['ausloeser']][0] ?? '') . ' · '
       . count($schritte) . ' Schritte';
$brotkrumen = [['Automationen', '/app/automationen.php'], [(string) $automation['name'], null]];
require __DIR__ . '/partials/kopf.php';
?>

<?php if (!$aktiv): ?>
  <div class="hinweis hinweis--warnung mb-4">
    <?= Icon::svg('alert', 17) ?>
    <div class="hinweis__text">
      <span class="hinweis__titel">Der Ablauf ist nicht eingeschaltet</span>
      Er wird nicht ausgelöst. Prüfe die Texte – besonders die Anrede und den Tonfall –
      und schalte ihn dann rechts ein.
    </div>
  </div>
<?php endif; ?>

<div class="raster raster--haupt-neben">
  <div class="stapel">
    <div class="karte">
      <div class="karte__koerper">
        <div class="strahl">
          <div class="strahl__punkt strahl__punkt--marke">
            <div class="strahl__zeit">Auslöser</div>
            <div class="strahl__titel">
              <?= Icon::svg(Automations::AUSLOESER[(string) $automation['ausloeser']][1] ?? 'zap', 15) ?>
              <?= Util::h(Automations::AUSLOESER[(string) $automation['ausloeser']][0] ?? '') ?>
            </div>
          </div>

          <?php foreach ($schritte as $i => $s):
            $config = Util::ausJson((string) $s['config']);
            $art = (string) $s['art']; ?>
            <div class="strahl__punkt<?= $art === 'warten' ? '' : ' strahl__punkt--marke' ?>">
              <div class="strahl__zeit">Schritt <?= $i + 1 ?></div>
              <div class="strahl__titel reihe reihe--eng">
                <?= Icon::svg(Automations::SCHRITTE[$art][1] ?? 'zap', 15) ?>
                <span><?= Util::h(Automations::SCHRITTE[$art][0] ?? $art) ?></span>
                <?php if ($art === 'warten'): ?>
                  <span class="pille"><?= (int) ($config['tage'] ?? 0) ?> Tage</span>
                <?php endif; ?>
                <?php if (!empty($config['werblich'])): ?>
                  <span class="pille pille--warnung">werblich</span>
                <?php endif; ?>
                <div class="fueller"></div>
                <?php if (Auth::darf('automations.write')): ?>
                  <form method="post" data-bestaetigen="Schritt entfernen?">
                    <?= Auth::csrfFeld() ?>
                    <input type="hidden" name="aktion" value="schritt_weg">
                    <input type="hidden" name="schritt_id" value="<?= (int) $s['id'] ?>">
                    <button class="btn btn--klein btn--geist btn--nur-symbol" type="submit"
                            aria-label="Entfernen"><?= Icon::svg('x', 13) ?></button>
                  </form>
                <?php endif; ?>
              </div>
              <?php if (!empty($config['betreff'])): ?>
                <div class="strahl__text"><strong><?= Util::h((string) $config['betreff']) ?></strong></div>
              <?php endif; ?>
              <?php if (!empty($config['text'])): ?>
                <div class="strahl__text" style="white-space:pre-wrap;margin-top:6px">
                  <?= Util::h(Util::kuerzen((string) $config['text'], 260)) ?></div>
              <?php endif; ?>
              <?php if (!empty($config['titel'])): ?>
                <div class="strahl__text"><?= Util::h((string) $config['titel']) ?></div>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>

          <?php if ($schritte === []): ?>
            <div class="strahl__punkt">
              <div class="strahl__text gedimmt">Noch kein Schritt. Rechts hinzufügen.</div>
            </div>
          <?php endif; ?>
        </div>
      </div>
      <?php if (Auth::darf('automations.write')): ?>
        <div class="karte__fuss">
          <div class="fueller"></div>
          <button class="btn btn--primaer btn--klein" data-modal-auf="modal-schritt">
            <?= Icon::svg('plus', 14) ?> Schritt hinzufügen</button>
        </div>
      <?php endif; ?>
    </div>

    <?php if ($laeufe !== []): ?>
    <div class="karte">
      <div class="karte__kopf"><h3>Läufe</h3>
        <span class="pille"><?= Tenant::count('automation_runs', 'automation_id = :a', ['a' => $id]) ?></span></div>
      <div class="tabelle-huelle">
        <table class="tabelle tabelle--eng">
          <thead><tr><th>Kunde</th><th>Schritt</th><th>Status</th><th>Nächster Lauf</th></tr></thead>
          <tbody>
          <?php foreach ($laeufe as $l): ?>
            <tr>
              <td><?= (int) $l['customer_id'] > 0
                    ? Util::h(Customers::nameVonId((int) $l['customer_id'])) : '<span class="gedimmt-2">—</span>' ?></td>
              <td><?= (int) $l['schritt'] ?> / <?= count($schritte) ?></td>
              <td><?= pille((string) $l['status'],
                    (string) $l['status'] === 'abgeschlossen' ? 'erfolg'
                    : ((string) $l['status'] === 'laufend' ? 'info' : '')) ?></td>
              <td class="gedimmt"><?= $l['naechster_lauf']
                    ? Util::h(Util::relativ((string) $l['naechster_lauf'])) : '—' ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
    <?php endif; ?>
  </div>

  <form method="post" class="karte">
    <?= Auth::csrfFeld() ?>
    <input type="hidden" name="aktion" value="speichern">
    <div class="karte__kopf"><h3>Einstellungen</h3></div>
    <div class="karte__koerper">
      <div class="feld"><label class="feld__label" for="a-name">Name</label>
        <input class="eingabe" id="a-name" name="name" value="<?= Util::attr((string) $automation['name']) ?>"></div>
      <div class="feld"><label class="feld__label" for="a-text">Beschreibung</label>
        <textarea class="eingabe" id="a-text" name="beschreibung" rows="2" data-waechst><?= Util::h((string) $automation['beschreibung']) ?></textarea></div>
      <div class="feld"><label class="feld__label" for="a-ausloeser">Auslöser</label>
        <select id="a-ausloeser" name="ausloeser">
          <?php foreach (Automations::AUSLOESER as $k => [$n, $i]): ?>
            <option value="<?= $k ?>"<?= (string) $automation['ausloeser'] === $k ? ' selected' : '' ?>>
              <?= Util::h($n) ?></option>
          <?php endforeach; ?>
        </select></div>
      <div class="feld"><label class="feld__label" for="a-status">Status</label>
        <select id="a-status" name="status">
          <option value="entwurf"<?= (string) $automation['status'] === 'entwurf' ? ' selected' : '' ?>>Entwurf</option>
          <option value="aktiv"<?= $aktiv ? ' selected' : '' ?>>Aktiv</option>
          <option value="pausiert"<?= (string) $automation['status'] === 'pausiert' ? ' selected' : '' ?>>Pausiert</option>
        </select></div>
      <div class="hinweis hinweis--still">
        <?= Icon::svg('shield', 16) ?>
        <div class="hinweis__text klein">Als <em>werblich</em> markierte Schritte gehen nur an
          Kunden mit Newsletter-Einwilligung. Terminerinnerungen und Hinweise zu gebuchten
          Leistungen sind nicht werblich.</div>
      </div>
    </div>
    <?php if (Auth::darf('automations.write')): ?>
      <div class="karte__fuss"><div class="fueller"></div>
        <button class="btn btn--primaer" type="submit">Speichern</button></div>
    <?php endif; ?>
  </form>
</div>

<?php if (Auth::darf('automations.write')): ?>
<dialog class="modal" id="modal-schritt">
  <form method="post">
    <?= Auth::csrfFeld() ?>
    <input type="hidden" name="aktion" value="schritt">
    <div class="modal__kopf"><h2>Schritt hinzufügen</h2>
      <button type="button" class="rundknopf" data-modal-zu aria-label="Schließen"><?= Icon::svg('x', 17) ?></button></div>
    <div class="modal__koerper">
      <div class="feld"><label class="feld__label" for="sc-art">Was soll passieren?</label>
        <select id="sc-art" name="art" onchange="
          document.getElementById('feld-mail').style.display = (this.value==='email'||this.value==='sms') ? '' : 'none';
          document.getElementById('feld-warten').style.display = this.value==='warten' ? '' : 'none';
          document.getElementById('feld-tag').style.display = this.value==='tag' ? '' : 'none';
          document.getElementById('feld-titel').style.display = (this.value==='aufgabe'||this.value==='angebot') ? '' : 'none';
          document.getElementById('feld-segment').style.display = (this.value==='bedingung'||this.value==='segment') ? '' : 'none';">
          <?php foreach (Automations::SCHRITTE as $k => [$n, $i]): ?>
            <option value="<?= $k ?>"><?= Util::h($n) ?></option>
          <?php endforeach; ?>
        </select></div>

      <div id="feld-mail">
        <div class="feld"><label class="feld__label" for="sc-betreff">Betreff</label>
          <input class="eingabe" id="sc-betreff" name="betreff" placeholder="Willkommen bei {pro}, {vorname}!"></div>
        <div class="feld"><label class="feld__label" for="sc-text">Text</label>
          <textarea class="eingabe" id="sc-text" name="text" rows="7" data-waechst
            placeholder="Hallo {vorname},&#10;&#10;schön, dass du da bist."></textarea>
          <div class="feld__hinweis">Platzhalter: {vorname}, {name}, {hcp}, {heimclub}, {pro}</div></div>
        <label class="haken"><input type="checkbox" name="werblich" value="1">
          <span class="haken__text">Werblicher Inhalt
            <span class="haken__hinweis">Dann geht die Mail nur an Kunden mit Newsletter-Einwilligung.</span></span></label>
      </div>

      <div id="feld-warten" style="display:none">
        <div class="feld"><label class="feld__label" for="sc-tage">Wie viele Tage warten?</label>
          <input class="eingabe" id="sc-tage" type="number" name="tage" value="3" min="0"></div>
      </div>

      <div id="feld-tag" style="display:none">
        <div class="feld"><label class="feld__label" for="sc-tag">Schlagwort</label>
          <input class="eingabe" id="sc-tag" name="tag" placeholder="Willkommensablauf"></div>
      </div>

      <div id="feld-titel" style="display:none">
        <div class="feld"><label class="feld__label" for="sc-titel">Text der Aufgabe</label>
          <input class="eingabe" id="sc-titel" name="titel" placeholder="Anrufen: {name}"></div>
      </div>

      <div id="feld-segment" style="display:none">
        <div class="feld"><label class="feld__label" for="sc-segment">Nur weiter, wenn im Segment</label>
          <select id="sc-segment" name="segment_id">
            <option value="0">— egal —</option>
            <?php foreach (Segments::alle() as $s): ?>
              <option value="<?= (int) $s['id'] ?>"><?= Util::h((string) $s['name']) ?></option>
            <?php endforeach; ?>
          </select>
          <div class="feld__hinweis">Trifft die Bedingung nicht zu, endet der Ablauf für diesen Kunden.</div></div>
      </div>
    </div>
    <div class="modal__fuss"><div class="fueller"></div>
      <button type="button" class="btn" data-modal-zu>Abbrechen</button>
      <button type="submit" class="btn btn--primaer">Hinzufügen</button></div>
  </form>
</dialog>
<?php endif; ?>

<?php require __DIR__ . '/partials/fuss.php'; ?>
