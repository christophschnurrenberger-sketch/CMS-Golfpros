<?php
/** Übungsbibliothek – der Baukasten für Trainingspläne. */
require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/partials/helfer.php';

Auth::fordern('modul.training');

if (App::istPost()) {
    Auth::csrfFordern();
    Auth::fordern('training.write');
    $id = App::postInt('id');

    if (App::aktion() === 'speichern') {
        $daten = [
            'titel' => App::post('titel'), 'beschreibung' => App::post('beschreibung'),
            'kategorie' => App::post('kategorie', 'allgemein'), 'video_url' => App::post('video_url'),
            'dauer_min' => App::postInt('dauer_min', 15), 'wiederholungen' => App::post('wiederholungen'),
            'ziel' => App::post('ziel'), 'schwierigkeit' => App::post('schwierigkeit', 'mittel'),
            'ausruestung' => App::post('ausruestung'),
        ];
        if ($daten['titel'] === '') {
            App::melden('Die Übung braucht einen Titel.', 'fehler');
        } else {
            Training::uebungSpeichern($daten, $id);
            App::melden($id > 0 ? 'Übung gespeichert.' : 'Übung angelegt.');
        }
    }
    if (App::aktion() === 'loeschen' && $id > 0) {
        Tenant::delete('exercises', $id);
        App::melden('Übung gelöscht.');
    }
    if (App::aktion() === 'standard') {
        Training::standardUebungenAnlegen();
        App::melden('Die Grundausstattung wurde angelegt.');
    }
    App::weiter('/app/uebungen.php?kategorie=' . rawurlencode(App::post('zurueck_kategorie')));
}

$kategorie = App::get('kategorie');
$suche = App::get('q');
$uebungen = Training::uebungen(['kategorie' => $kategorie, 'suche' => $suche]);
$alle = Training::uebungen();

$titel = 'Übungen';
$unter = count($alle) . ' Übungen in ' . count(array_unique(array_column($alle, 'kategorie'))) . ' Kategorien';
$brotkrumen = [['Training', '/app/training.php'], ['Übungen', null]];
$aktionen = Auth::darf('training.write')
    ? '<button class="btn btn--primaer" data-modal-auf="modal-uebung" data-modal-titel="Neue Übung">'
      . Icon::svg('plus', 15) . ' Übung anlegen</button>' : '';
require __DIR__ . '/partials/kopf.php';
?>

<?php if ($alle === []): ?>
  <div class="karte"><div class="karte__koerper">
    <?= leerzustand('dumbbell', 'Die Bibliothek ist leer',
          'Übungen sind die Bausteine jedes Trainingsplans. Du kannst sie einzeln anlegen – '
          . 'oder mit einer Grundausstattung aus 21 erprobten Übungen starten und sie anpassen.', []) ?>
    <?php if (Auth::darf('training.write')): ?>
      <form method="post" class="mitte">
        <?= Auth::csrfFeld() ?>
        <input type="hidden" name="aktion" value="standard">
        <button class="btn btn--primaer" type="submit">
          <?= Icon::svg('layers', 15) ?> Grundausstattung anlegen</button>
      </form>
    <?php endif; ?>
  </div></div>
<?php else: ?>

<div class="reihe reihe--eng reihe--umbruch mb-4">
  <a class="pille <?= $kategorie === '' ? 'pille--marke' : 'pille--offen' ?>"
     href="<?= Util::attr(App::url('/app/uebungen.php')) ?>">Alle</a>
  <?php foreach (Training::KATEGORIEN as $k => $name):
    $n = count(array_filter($alle, static fn($u) => (string) $u['kategorie'] === $k));
    if ($n === 0) { continue; } ?>
    <a class="pille <?= $kategorie === $k ? 'pille--marke' : 'pille--offen' ?>"
       href="<?= Util::attr(App::url('/app/uebungen.php?kategorie=' . $k)) ?>">
      <?= Util::h($name) ?> <span class="gedimmt-2"><?= $n ?></span></a>
  <?php endforeach; ?>
</div>

<div class="karte mb-4">
  <form method="get" class="filterleiste">
    <?php if ($kategorie !== ''): ?>
      <input type="hidden" name="kategorie" value="<?= Util::attr($kategorie) ?>">
    <?php endif; ?>
    <div class="such-feld">
      <?= Icon::svg('search', 16) ?>
      <input class="eingabe" name="q" value="<?= Util::attr($suche) ?>" data-such-absenden
             placeholder="Übung suchen" aria-label="Übungen durchsuchen">
    </div>
  </form>
</div>

<div class="raster raster--auto-gross">
  <?php foreach ($uebungen as $u): ?>
    <div class="karte">
      <div class="karte__koerper">
        <div class="reihe reihe--eng mb-2">
          <?= pille(Training::KATEGORIEN[(string) $u['kategorie']] ?? (string) $u['kategorie'], 'offen') ?>
          <?= pille(Training::SCHWIERIGKEIT[(string) $u['schwierigkeit']] ?? '',
                (string) $u['schwierigkeit'] === 'schwer' ? 'warnung' : '') ?>
          <div class="fueller"></div>
          <span class="klein gedimmt"><?= (int) $u['dauer_min'] ?> Min</span>
        </div>
        <h3 class="mb-2"><?= Util::h((string) $u['titel']) ?></h3>
        <p class="klein gedimmt" style="line-height:1.6"><?= Util::h((string) $u['beschreibung']) ?></p>
        <?php if ((string) $u['ziel'] !== ''): ?>
          <div class="reihe reihe--eng mt-3 klein">
            <?= Icon::svg('target', 14) ?>
            <span class="gedimmt"><?= Util::h((string) $u['ziel']) ?></span>
          </div>
        <?php endif; ?>
        <?php if ((string) $u['wiederholungen'] !== ''): ?>
          <div class="reihe reihe--eng mt-2 klein">
            <?= Icon::svg('repeat', 14) ?>
            <span class="gedimmt"><?= Util::h((string) $u['wiederholungen']) ?></span>
          </div>
        <?php endif; ?>
      </div>
      <?php if (Auth::darf('training.write')): ?>
      <div class="karte__fuss">
        <div class="fueller"></div>
        <button class="btn btn--klein btn--geist" data-modal-auf="modal-uebung"
                data-setzid="<?= (int) $u['id'] ?>"
                data-setztitel="<?= Util::attr((string) $u['titel']) ?>"
                data-setzbeschreibung="<?= Util::attr((string) $u['beschreibung']) ?>"
                data-setzkategorie="<?= Util::attr((string) $u['kategorie']) ?>"
                data-setzdauer_min="<?= (int) $u['dauer_min'] ?>"
                data-setzwiederholungen="<?= Util::attr((string) $u['wiederholungen']) ?>"
                data-setzziel="<?= Util::attr((string) $u['ziel']) ?>"
                data-setzvideo_url="<?= Util::attr((string) $u['video_url']) ?>"
                data-modal-titel="Übung bearbeiten">
          <?= Icon::svg('edit', 14) ?> Bearbeiten</button>
      </div>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if (Auth::darf('training.write')): ?>
<dialog class="modal" id="modal-uebung">
  <form method="post">
    <?= Auth::csrfFeld() ?>
    <input type="hidden" name="aktion" value="speichern">
    <input type="hidden" name="id" value="0">
    <input type="hidden" name="zurueck_kategorie" value="<?= Util::attr($kategorie) ?>">
    <div class="modal__kopf"><h2>Übung</h2>
      <button type="button" class="rundknopf" data-modal-zu aria-label="Schließen"><?= Icon::svg('x', 17) ?></button></div>
    <div class="modal__koerper">
      <div class="feld"><label class="feld__label" for="u-titel">Titel</label>
        <input class="eingabe" id="u-titel" name="titel" required placeholder="Uhrzeigerdrill"></div>
      <div class="feld"><label class="feld__label" for="u-text">Anleitung</label>
        <textarea class="eingabe" id="u-text" name="beschreibung" rows="4" data-waechst
                  placeholder="Wie wird die Übung ausgeführt? Diesen Text sieht auch der Kunde."></textarea></div>
      <div class="feld-reihe feld-reihe--3">
        <div class="feld"><label class="feld__label" for="u-kat">Kategorie</label>
          <select id="u-kat" name="kategorie">
            <?php foreach (Training::KATEGORIEN as $k => $n): ?>
              <option value="<?= $k ?>"<?= $kategorie === $k ? ' selected' : '' ?>><?= Util::h($n) ?></option>
            <?php endforeach; ?>
          </select></div>
        <div class="feld"><label class="feld__label" for="u-dauer">Dauer (Min)</label>
          <input class="eingabe" id="u-dauer" type="number" name="dauer_min" value="15" min="1"></div>
        <div class="feld"><label class="feld__label" for="u-schwer">Schwierigkeit</label>
          <select id="u-schwer" name="schwierigkeit">
            <?php foreach (Training::SCHWIERIGKEIT as $k => $n): ?>
              <option value="<?= $k ?>"<?= $k === 'mittel' ? ' selected' : '' ?>><?= $n ?></option>
            <?php endforeach; ?>
          </select></div>
      </div>
      <div class="feld-reihe feld-reihe--2">
        <div class="feld"><label class="feld__label" for="u-wdh">Umfang</label>
          <input class="eingabe" id="u-wdh" name="wiederholungen" placeholder="20 Bälle, 3 × 12"></div>
        <div class="feld"><label class="feld__label" for="u-ziel">Ziel</label>
          <input class="eingabe" id="u-ziel" name="ziel" placeholder="Sicherheit auf kurzen Putts"></div>
      </div>
      <div class="feld"><label class="feld__label" for="u-video">Video (YouTube oder Vimeo)</label>
        <input class="eingabe" id="u-video" type="url" name="video_url" placeholder="https://"></div>
    </div>
    <div class="modal__fuss"><div class="fueller"></div>
      <button type="button" class="btn" data-modal-zu>Abbrechen</button>
      <button type="submit" class="btn btn--primaer">Speichern</button></div>
  </form>
</dialog>
<?php endif; ?>

<?php require __DIR__ . '/partials/fuss.php'; ?>
