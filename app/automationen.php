<?php
/** Automationen – Abläufe, die ohne Zutun laufen. */
require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/partials/helfer.php';

Auth::fordern('modul.automations');

if (App::istPost()) {
    Auth::csrfFordern();
    Auth::fordern('automations.write');

    if (App::aktion() === 'vorlage') {
        $vorlagen = Automations::vorlagen();
        $i = App::postInt('nummer');
        if (isset($vorlagen[$i])) {
            $id = Automations::vorlageAnlegen($vorlagen[$i]);
            App::melden('Ablauf angelegt – als Entwurf. Prüfe die Texte und schalte ihn dann ein.', 'info');
            App::weiter('/app/automation.php?id=' . $id);
        }
    }
    if (App::aktion() === 'neu') {
        $id = Tenant::insert('automations', [
            'name' => App::post('name') ?: 'Neuer Ablauf',
            'ausloeser' => App::post('ausloeser', 'customer_created'),
            'status' => 'entwurf',
        ]);
        App::weiter('/app/automation.php?id=' . $id);
    }
    if (App::aktion() === 'umschalten') {
        $a = Tenant::find('automations', App::postInt('id'));
        if ($a) {
            Tenant::update('automations', (int) $a['id'],
                ['status' => (string) $a['status'] === 'aktiv' ? 'pausiert' : 'aktiv']);
            App::melden((string) $a['status'] === 'aktiv' ? 'Ablauf pausiert.' : 'Ablauf eingeschaltet.');
        }
    }
    App::weiter('/app/automationen.php');
}

$automationen = Tenant::all('automations', '', [], 'status DESC, id DESC');
$laufend = Tenant::count('automation_runs', "status = 'laufend'");

$titel = 'Automationen';
$unter = count(array_filter($automationen, static fn($a) => (string) $a['status'] === 'aktiv'))
       . ' aktive Abläufe · ' . $laufend . ' Kunden gerade darin';
$aktionen = Auth::darf('automations.write')
    ? '<button class="btn btn--primaer" data-modal-auf="modal-automation">'
      . Icon::svg('plus', 15) . ' Ablauf anlegen</button>' : '';
require __DIR__ . '/partials/kopf.php';
?>

<?php if ($automationen === []): ?>
  <div class="karte mb-5"><div class="karte__koerper">
    <?= leerzustand('automations', 'Noch kein Ablauf',
          'Eine Automation nimmt dir wiederkehrende Nachrichten ab: die Begrüßung neuer Kunden, '
          . 'das Nachfassen bei Anfragen, der Hinweis auf ein auslaufendes Paket. '
          . 'Fünf erprobte Abläufe stehen als Vorlage bereit.', []) ?>
  </div></div>
<?php else: ?>
  <div class="raster raster--auto-gross mb-5">
    <?php foreach ($automationen as $a):
      $schritte = Automations::schritte((int) $a['id']);
      $aktiv = (string) $a['status'] === 'aktiv'; ?>
      <div class="karte">
        <div class="karte__koerper">
          <div class="reihe reihe--eng mb-3">
            <?= pille(Automations::AUSLOESER[(string) $a['ausloeser']][0] ?? (string) $a['ausloeser'], 'offen') ?>
            <div class="fueller"></div>
            <?php if (Auth::darf('automations.write')): ?>
              <form method="post">
                <?= Auth::csrfFeld() ?>
                <input type="hidden" name="aktion" value="umschalten">
                <input type="hidden" name="id" value="<?= (int) $a['id'] ?>">
                <label class="schalter">
                  <input type="checkbox"<?= $aktiv ? ' checked' : '' ?>
                         onchange="if(window.Blick)window.Blick.merken(this);this.form.submit()">
                  <span class="schalter__spur"></span>
                </label>
              </form>
            <?php else: ?>
              <?= pille($aktiv ? 'aktiv' : 'aus', $aktiv ? 'erfolg' : '') ?>
            <?php endif; ?>
          </div>
          <h3 class="mb-2"><?= Util::h((string) $a['name']) ?></h3>
          <p class="klein gedimmt" style="min-height:2.4em"><?= Util::h((string) $a['beschreibung']) ?></p>
          <div class="reihe reihe--eng mt-3 klein gedimmt">
            <?= Icon::svg('list', 14) ?> <?= count($schritte) ?> Schritte
            <span>·</span>
            <?= Icon::svg('customers', 14) ?> <?= (int) $a['gestartet'] ?> gestartet
            <span>·</span>
            <?= Icon::svg('check', 14) ?> <?= (int) $a['abgeschlossen'] ?> durch
          </div>
        </div>
        <div class="karte__fuss">
          <div class="fueller"></div>
          <a class="btn btn--klein" href="<?= Util::attr(App::url('/app/automation.php?id=' . (int) $a['id'])) ?>">
            <?= Icon::svg('edit', 14) ?> Bearbeiten</a>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<?php if (Auth::darf('automations.write')): ?>
<div class="karte">
  <div class="karte__kopf"><h2>Vorlagen</h2>
    <div class="fueller"></div>
    <span class="klein gedimmt">erprobte Abläufe, sofort anpassbar</span></div>
  <div class="karte__koerper">
    <div class="raster raster--2">
      <?php foreach (Automations::vorlagen() as $i => $v): ?>
        <form method="post" class="kachel-link" style="align-items:flex-start">
          <?= Auth::csrfFeld() ?>
          <input type="hidden" name="aktion" value="vorlage">
          <input type="hidden" name="nummer" value="<?= $i ?>">
          <span class="kachel-link__symbol">
            <?= Icon::svg(Automations::AUSLOESER[(string) $v['ausloeser']][1] ?? 'zap', 17) ?></span>
          <div style="flex:1;min-width:0">
            <div class="kachel-link__titel"><?= Util::h((string) $v['name']) ?></div>
            <div class="kachel-link__text"><?= Util::h((string) $v['beschreibung']) ?></div>
            <div class="reihe reihe--eng mt-3" style="flex-wrap:wrap">
              <?php foreach ($v['schritte'] as [$art, $config]): ?>
                <span class="pille pille--offen">
                  <?= Icon::svg(Automations::SCHRITTE[$art][1] ?? 'zap', 11) ?>
                  <?= Util::h(Automations::SCHRITTE[$art][0] ?? $art) ?>
                  <?php if ($art === 'warten'): ?>
                    <?= (int) ($config['tage'] ?? 0) ?> T
                  <?php endif; ?>
                </span>
              <?php endforeach; ?>
            </div>
          </div>
          <button class="btn btn--klein btn--primaer" type="submit">Übernehmen</button>
        </form>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<dialog class="modal modal--schmal" id="modal-automation">
  <form method="post">
    <?= Auth::csrfFeld() ?>
    <input type="hidden" name="aktion" value="neu">
    <div class="modal__kopf"><h2>Eigener Ablauf</h2>
      <button type="button" class="rundknopf" data-modal-zu aria-label="Schließen"><?= Icon::svg('x', 17) ?></button></div>
    <div class="modal__koerper">
      <div class="feld"><label class="feld__label" for="au-name">Name</label>
        <input class="eingabe" id="au-name" name="name" required autofocus
               placeholder="Nach dem ersten Training"></div>
      <div class="feld"><label class="feld__label" for="au-ausloeser">Auslöser</label>
        <select id="au-ausloeser" name="ausloeser">
          <?php foreach (Automations::AUSLOESER as $k => [$n, $i]): ?>
            <option value="<?= $k ?>"><?= Util::h($n) ?></option>
          <?php endforeach; ?>
        </select>
        <div class="feld__hinweis">Der Ablauf startet, sobald dieses Ereignis eintritt.</div></div>
    </div>
    <div class="modal__fuss"><div class="fueller"></div>
      <button type="button" class="btn" data-modal-zu>Abbrechen</button>
      <button type="submit" class="btn btn--primaer">Anlegen</button></div>
  </form>
</dialog>
<?php endif; ?>

<?php require __DIR__ . '/partials/fuss.php'; ?>
