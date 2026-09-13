<?php
/** Online-Kurse – Übersicht. */
require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/partials/helfer.php';

Auth::fordern('modul.courses');

if (App::istPost()) {
    Auth::csrfFordern();
    Auth::fordern('courses.write');
    if (App::aktion() === 'neu') {
        $id = Courses::speichern([
            'titel' => App::post('titel') ?: 'Neuer Kurs',
            'kurztext' => App::post('kurztext'),
            'preis_cent' => Util::centAus(App::post('preis')),
            'niveau' => App::post('niveau', 'anfaenger'),
            'zertifikat' => App::postBool('zertifikat') ? 1 : 0,
            'status' => 'entwurf',
        ]);
        App::weiter('/app/kurs.php?id=' . $id);
    }
}

$kurse = Tenant::all('courses', '', [], 'position, id DESC');

$titel = 'Kurse';
$unter = count($kurse) . ' Kurse · ' . Tenant::count('course_enrollments') . ' Einschreibungen';
$aktionen = Auth::darf('courses.write')
    ? '<button class="btn btn--primaer" data-modal-auf="modal-kurs">' . Icon::svg('plus', 15) . ' Kurs anlegen</button>' : '';
require __DIR__ . '/partials/kopf.php';
?>

<?php if ($kurse === []): ?>
  <div class="karte"><div class="karte__koerper">
    <?= leerzustand('courses', 'Noch kein Kurs',
          'Ein Onlinekurs verkauft sich, während du auf der Range stehst. Module, Lektionen, '
          . 'Videos, Quiz und Zertifikat – der Kunde arbeitet ihn in seinem Tempo durch.', []) ?>
    <?php if (Auth::darf('courses.write')): ?>
      <div class="mitte"><button class="btn btn--primaer" data-modal-auf="modal-kurs">
        <?= Icon::svg('plus', 15) ?> Ersten Kurs anlegen</button></div>
    <?php endif; ?>
  </div></div>
<?php else: ?>
  <div class="raster raster--auto-gross">
    <?php foreach ($kurse as $k):
      $z = Courses::kennzahlen((int) $k['id']); ?>
      <div class="karte">
        <div class="karte__koerper">
          <div class="reihe reihe--eng mb-3">
            <?= pille((string) $k['status'] === 'veroeffentlicht' ? 'Veröffentlicht' : 'Entwurf',
                  (string) $k['status'] === 'veroeffentlicht' ? 'erfolg' : '') ?>
            <?php if ((int) $k['zertifikat'] === 1): ?><?= pille('Zertifikat', 'akzent') ?><?php endif; ?>
            <div class="fueller"></div>
            <span class="halbfett"><?= (int) $k['preis_cent'] > 0
              ? Util::h(Util::geldKurz((int) $k['preis_cent'])) : 'Kostenlos' ?></span>
          </div>
          <h3 class="mb-2"><?= Util::h((string) $k['titel']) ?></h3>
          <p class="klein gedimmt" style="min-height:2.6em"><?= Util::h(Util::kuerzen((string) $k['kurztext'], 110)) ?></p>
          <div class="reihe reihe--eng mt-3 klein gedimmt">
            <?= Icon::svg('list', 14) ?> <?= Courses::lektionenAnzahl((int) $k['id']) ?> Lektionen
            <span>·</span>
            <?= Icon::svg('clock', 14) ?> <?= Util::zahl(Courses::dauer((int) $k['id']) / 60, 1) ?> h
          </div>
          <?php if ($z['teilnehmer'] > 0): ?>
            <div class="mt-4">
              <div class="reihe klein mb-2">
                <span class="gedimmt"><?= $z['teilnehmer'] ?> Teilnehmer</span>
                <div class="fueller"></div>
                <span class="halbfett"><?= $z['fortschritt'] ?> % Ø</span>
              </div>
              <?= balken($z['fortschritt']) ?>
            </div>
          <?php endif; ?>
        </div>
        <div class="karte__fuss">
          <span class="klein gedimmt"><?= $z['abgeschlossen'] ?> abgeschlossen</span>
          <div class="fueller"></div>
          <a class="btn btn--klein" href="<?= Util::attr(App::url('/app/kurs.php?id=' . (int) $k['id'])) ?>">
            <?= Icon::svg('edit', 14) ?> Bearbeiten</a>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<?php if (Auth::darf('courses.write')): ?>
<dialog class="modal modal--schmal" id="modal-kurs">
  <form method="post">
    <?= Auth::csrfFeld() ?>
    <input type="hidden" name="aktion" value="neu">
    <div class="modal__kopf"><h2>Neuer Kurs</h2>
      <button type="button" class="rundknopf" data-modal-zu aria-label="Schließen"><?= Icon::svg('x', 17) ?></button></div>
    <div class="modal__koerper">
      <div class="feld"><label class="feld__label" for="k-titel">Titel</label>
        <input class="eingabe" id="k-titel" name="titel" required autofocus placeholder="Putten von Grund auf"></div>
      <div class="feld"><label class="feld__label" for="k-kurz">Kurzbeschreibung</label>
        <input class="eingabe" id="k-kurz" name="kurztext" placeholder="Für alle, die auf dem Grün Schläge verschenken."></div>
      <div class="feld-reihe feld-reihe--2">
        <div class="feld"><label class="feld__label" for="k-preis">Preis</label>
          <div class="eingabe-gruppe">
            <input class="eingabe" id="k-preis" name="preis" value="0,00">
            <span class="eingabe-gruppe__anhang"><?= Util::h(Tenant::waehrung()) ?></span></div>
          <div class="feld__hinweis">0 = kostenlos</div></div>
        <div class="feld"><label class="feld__label" for="k-niveau">Niveau</label>
          <select id="k-niveau" name="niveau">
            <option value="anfaenger">Anfänger</option>
            <option value="mittel">Fortgeschritten</option>
            <option value="fortgeschritten">Turnierspieler</option>
          </select></div>
      </div>
      <label class="haken"><input type="checkbox" name="zertifikat" value="1">
        <span class="haken__text">Zertifikat am Ende</span></label>
    </div>
    <div class="modal__fuss"><div class="fueller"></div>
      <button type="button" class="btn" data-modal-zu>Abbrechen</button>
      <button type="submit" class="btn btn--primaer">Anlegen</button></div>
  </form>
</dialog>
<?php endif; ?>

<?php require __DIR__ . '/partials/fuss.php'; ?>
