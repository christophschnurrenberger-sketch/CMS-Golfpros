<?php
/** Kurs bearbeiten: Module, Lektionen, Teilnehmer. */
require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/partials/helfer.php';

Auth::fordern('modul.courses');

$id = App::getInt('id');
$kurs = Tenant::find('courses', $id);
if (!$kurs) {
    App::melden('Dieser Kurs wurde nicht gefunden.', 'fehler');
    App::weiter('/app/kurse.php');
}

if (App::istPost()) {
    Auth::csrfFordern();
    Auth::fordern('courses.write');
    $aktion = App::aktion();

    if ($aktion === 'speichern') {
        Courses::speichern([
            'titel' => App::post('titel'), 'kurztext' => App::post('kurztext'),
            'beschreibung' => App::post('beschreibung'),
            'preis_cent' => Util::centAus(App::post('preis')),
            'niveau' => App::post('niveau'), 'status' => App::post('status'),
            'zertifikat' => App::postBool('zertifikat') ? 1 : 0,
        ], $id);
        App::melden('Kurs gespeichert.');
    }
    if ($aktion === 'modul') {
        Tenant::insert('course_modules', [
            'course_id' => $id, 'titel' => App::post('titel') ?: 'Neues Modul',
            'position' => Tenant::count('course_modules', 'course_id = :c', ['c' => $id]),
        ]);
    }
    if ($aktion === 'lektion') {
        Tenant::insert('course_lessons', [
            'course_id' => $id, 'module_id' => App::postInt('module_id'),
            'titel' => App::post('titel') ?: 'Neue Lektion',
            'art' => App::post('art', 'video'),
            'video_url' => App::post('video_url'),
            'inhalt' => App::postRoh('inhalt'),
            'dauer_min' => App::postInt('dauer_min', 5),
            'position' => Tenant::count('course_lessons', 'course_id = :c', ['c' => $id]),
        ]);
    }
    if ($aktion === 'lektion_loeschen') {
        Tenant::deleteWhere('course_lessons', 'id = :l AND course_id = :c',
            ['l' => App::postInt('lektion_id'), 'c' => $id]);
    }
    if ($aktion === 'modul_loeschen') {
        $mid = App::postInt('modul_id');
        Tenant::updateWhere('course_lessons', ['module_id' => 0], 'module_id = :m', ['m' => $mid]);
        Tenant::deleteWhere('course_modules', 'id = :m AND course_id = :c', ['m' => $mid, 'c' => $id]);
    }
    if ($aktion === 'einschreiben') {
        Courses::einschreiben($id, App::postInt('customer_id'));
        App::melden('Teilnehmer eingeschrieben.');
    }
    App::weiter('/app/kurs.php?id=' . $id);
}

$aufbau = Courses::aufbau($id);
$z = Courses::kennzahlen($id);
$teilnehmer = DB::all(
    'SELECT e.*, c.vorname, c.nachname FROM course_enrollments e
     JOIN customers c ON c.id = e.customer_id
     WHERE e.workspace_id = :w AND e.course_id = :c ORDER BY e.fortschritt DESC',
    ['w' => Tenant::id(), 'c' => $id]
);

$titel = (string) $kurs['titel'];
$unter = Courses::lektionenAnzahl($id) . ' Lektionen · ' . Util::zahl(Courses::dauer($id) / 60, 1)
       . ' Stunden · ' . $z['teilnehmer'] . ' Teilnehmer';
$brotkrumen = [['Kurse', '/app/kurse.php'], [(string) $kurs['titel'], null]];
require __DIR__ . '/partials/kopf.php';
?>

<div class="reiter" data-reiter-gruppe="kurs">
  <button class="reiter__teil ist-aktiv" data-reiter="aufbau">Aufbau</button>
  <button class="reiter__teil" data-reiter="teilnehmer">Teilnehmer
    <span class="navi__zahl"><?= count($teilnehmer) ?></span></button>
  <button class="reiter__teil" data-reiter="einstellungen">Einstellungen</button>
</div>

<div data-reiter-feld="aufbau" data-reiter-gruppe="kurs">
  <div class="raster raster--haupt-neben">
    <div class="stapel">
      <?php if ($aufbau === []): ?>
        <div class="karte"><div class="karte__koerper">
          <?= leerzustand('layers', 'Noch kein Modul',
                'Ein Kurs besteht aus Modulen, ein Modul aus Lektionen. Fang mit drei bis fünf '
                . 'Modulen an – mehr überfordert die meisten Teilnehmer.', []) ?>
        </div></div>
      <?php endif; ?>

      <?php foreach ($aufbau as $teil):
        $m = $teil['modul']; ?>
        <div class="karte">
          <div class="karte__kopf">
            <h3><?= Util::h((string) $m['titel']) ?></h3>
            <span class="pille"><?= count($teil['lektionen']) ?></span>
            <div class="fueller"></div>
            <?php if (Auth::darf('courses.write') && (int) $m['id'] > 0): ?>
              <button class="btn btn--klein btn--geist" data-modal-auf="modal-lektion"
                      data-setzmodule_id="<?= (int) $m['id'] ?>"
                      data-modal-titel="Lektion in „<?= Util::attr((string) $m['titel']) ?>“">
                <?= Icon::svg('plus', 14) ?> Lektion</button>
              <form method="post" style="display:inline" data-bestaetigen="Modul löschen? Die Lektionen bleiben erhalten.">
                <?= Auth::csrfFeld() ?>
                <input type="hidden" name="aktion" value="modul_loeschen">
                <input type="hidden" name="modul_id" value="<?= (int) $m['id'] ?>">
                <button class="btn btn--klein btn--geist btn--nur-symbol" type="submit"
                        aria-label="Modul löschen"><?= Icon::svg('trash', 14) ?></button>
              </form>
            <?php endif; ?>
          </div>
          <div class="karte__koerper karte__koerper--eng">
            <?php if ($teil['lektionen'] === []): ?>
              <p class="klein gedimmt mitte" style="padding:var(--r3) 0">Noch keine Lektion.</p>
            <?php else: ?>
              <div class="stapel stapel--eng">
                <?php foreach ($teil['lektionen'] as $i => $l): ?>
                  <div class="kachel-link" style="align-items:center">
                    <span class="kachel-link__symbol" style="width:30px;height:30px;border-radius:9px">
                      <?= Icon::svg(Courses::LEKTIONSARTEN[(string) $l['art']][1] ?? 'play', 15) ?></span>
                    <div style="flex:1;min-width:0">
                      <div class="kachel-link__titel"><?= $i + 1 ?>. <?= Util::h((string) $l['titel']) ?></div>
                      <div class="kachel-link__text">
                        <?= Util::h(Courses::LEKTIONSARTEN[(string) $l['art']][0] ?? '') ?>
                        · <?= (int) $l['dauer_min'] ?> Min
                        <?php if ((int) $l['vorschau'] === 1): ?> · kostenlose Vorschau<?php endif; ?>
                      </div>
                    </div>
                    <?php if (Auth::darf('courses.write')): ?>
                      <form method="post" data-bestaetigen="Lektion löschen?">
                        <?= Auth::csrfFeld() ?>
                        <input type="hidden" name="aktion" value="lektion_loeschen">
                        <input type="hidden" name="lektion_id" value="<?= (int) $l['id'] ?>">
                        <button class="btn btn--klein btn--geist btn--nur-symbol" type="submit"
                                aria-label="Löschen"><?= Icon::svg('x', 14) ?></button>
                      </form>
                    <?php endif; ?>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>

      <?php if (Auth::darf('courses.write')): ?>
        <form method="post" class="karte">
          <?= Auth::csrfFeld() ?>
          <input type="hidden" name="aktion" value="modul">
          <div class="karte__koerper">
            <div class="reihe reihe--eng">
              <input class="eingabe" name="titel" placeholder="Name des nächsten Moduls" required>
              <button class="btn btn--primaer umbruch-nein" type="submit">
                <?= Icon::svg('plus', 15) ?> Modul</button>
            </div>
          </div>
        </form>
      <?php endif; ?>
    </div>

    <div class="stapel">
      <div class="karte">
        <div class="karte__kopf"><h3>Kurs im Überblick</h3></div>
        <div class="karte__koerper karte__koerper--eng">
          <div class="stapel stapel--eng klein">
            <?php foreach ([
              ['Module', count($aufbau)],
              ['Lektionen', Courses::lektionenAnzahl($id)],
              ['Gesamtdauer', Util::zahl(Courses::dauer($id) / 60, 1) . ' Stunden'],
              ['Teilnehmer', $z['teilnehmer']],
              ['Abschlussquote', $z['quote'] . ' %'],
              ['Preis', (int) $kurs['preis_cent'] > 0 ? Util::geld((int) $kurs['preis_cent']) : 'Kostenlos'],
            ] as [$label, $wert]): ?>
              <div class="reihe"><span class="gedimmt"><?= Util::h($label) ?></span>
                <div class="fueller"></div><span class="halbfett"><?= Util::h((string) $wert) ?></span></div>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
      <?php if ((string) $kurs['status'] !== 'veroeffentlicht'): ?>
        <div class="hinweis hinweis--warnung">
          <?= Icon::svg('alert', 17) ?>
          <div class="hinweis__text">Der Kurs ist ein Entwurf und auf der Website nicht sichtbar.
            Unter <em>Einstellungen</em> veröffentlichen.</div>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<div data-reiter-feld="teilnehmer" data-reiter-gruppe="kurs" class="versteckt">
  <div class="karte">
    <div class="karte__kopf"><h3>Teilnehmer</h3>
      <div class="fueller"></div>
      <?php if (Auth::darf('courses.write')): ?>
        <button class="btn btn--klein btn--primaer" data-modal-auf="modal-einschreiben">
          <?= Icon::svg('user-plus', 14) ?> Einschreiben</button>
      <?php endif; ?>
    </div>
    <?php if ($teilnehmer === []): ?>
      <div class="karte__koerper">
        <?= leerzustand('customers', 'Noch keine Teilnehmer',
              'Über den Shop kaufen Kunden den Kurs selbst. Du kannst jemanden auch direkt '
              . 'einschreiben – etwa als Zugabe zu einem Trainingspaket.', []) ?>
      </div>
    <?php else: ?>
      <div class="tabelle-huelle">
        <table class="tabelle">
          <thead><tr><th>Teilnehmer</th><th>Fortschritt</th><th class="nicht-mobil">Begonnen</th>
            <th>Status</th></tr></thead>
          <tbody>
          <?php foreach ($teilnehmer as $t): ?>
            <tr>
              <td><?= person(trim($t['vorname'] . ' ' . $t['nachname']),
                    ['url' => '/app/kunde.php?id=' . (int) $t['customer_id']]) ?></td>
              <td style="min-width:150px"><?= balken((int) $t['fortschritt']) ?>
                <div class="winzig gedimmt-2 mt-2"><?= (int) $t['fortschritt'] ?> %</div></td>
              <td class="nicht-mobil gedimmt"><?= Util::h(Util::datum((string) $t['begonnen'])) ?></td>
              <td>
                <?php if ($t['abgeschlossen']): ?>
                  <?= pille('Abgeschlossen', 'erfolg') ?>
                  <?php if ((string) $t['zertifikat_code'] !== ''): ?>
                    <div class="winzig mono gedimmt-2 mt-2"><?= Util::h((string) $t['zertifikat_code']) ?></div>
                  <?php endif; ?>
                <?php elseif ((int) $t['fortschritt'] > 0): ?>
                  <?= pille('Läuft', 'info') ?>
                <?php else: ?>
                  <?= pille('Nicht begonnen', 'warnung') ?>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>

<div data-reiter-feld="einstellungen" data-reiter-gruppe="kurs" class="versteckt">
  <form method="post" class="karte" style="max-width:720px">
    <?= Auth::csrfFeld() ?>
    <input type="hidden" name="aktion" value="speichern">
    <div class="karte__koerper">
      <div class="feld"><label class="feld__label" for="e-titel">Titel</label>
        <input class="eingabe" id="e-titel" name="titel" value="<?= Util::attr((string) $kurs['titel']) ?>"></div>
      <div class="feld"><label class="feld__label" for="e-kurz">Kurzbeschreibung</label>
        <input class="eingabe" id="e-kurz" name="kurztext" value="<?= Util::attr((string) $kurs['kurztext']) ?>"></div>
      <div class="feld"><label class="feld__label" for="e-text">Beschreibung</label>
        <textarea class="eingabe" id="e-text" name="beschreibung" rows="6" data-waechst><?= Util::h((string) $kurs['beschreibung']) ?></textarea></div>
      <div class="feld-reihe feld-reihe--3">
        <div class="feld"><label class="feld__label" for="e-preis">Preis</label>
          <div class="eingabe-gruppe">
            <input class="eingabe" id="e-preis" name="preis"
                   value="<?= Util::attr(number_format((int) $kurs['preis_cent'] / 100, 2, ',', '')) ?>">
            <span class="eingabe-gruppe__anhang"><?= Util::h(Tenant::waehrung()) ?></span></div></div>
        <div class="feld"><label class="feld__label" for="e-niveau">Niveau</label>
          <select id="e-niveau" name="niveau">
            <?php foreach (['anfaenger' => 'Anfänger', 'mittel' => 'Fortgeschritten',
                            'fortgeschritten' => 'Turnierspieler'] as $k => $v): ?>
              <option value="<?= $k ?>"<?= (string) $kurs['niveau'] === $k ? ' selected' : '' ?>><?= $v ?></option>
            <?php endforeach; ?>
          </select></div>
        <div class="feld"><label class="feld__label" for="e-status">Status</label>
          <select id="e-status" name="status">
            <option value="entwurf"<?= (string) $kurs['status'] === 'entwurf' ? ' selected' : '' ?>>Entwurf</option>
            <option value="veroeffentlicht"<?= (string) $kurs['status'] === 'veroeffentlicht' ? ' selected' : '' ?>>Veröffentlicht</option>
          </select></div>
      </div>
      <label class="haken"><input type="checkbox" name="zertifikat" value="1"<?= (int) $kurs['zertifikat'] === 1 ? ' checked' : '' ?>>
        <span class="haken__text">Zertifikat ausstellen
          <span class="haken__hinweis">Nach der letzten Lektion bekommt der Teilnehmer einen Code.</span></span></label>
    </div>
    <?php if (Auth::darf('courses.write')): ?>
      <div class="karte__fuss"><div class="fueller"></div>
        <button class="btn btn--primaer" type="submit">Speichern</button></div>
    <?php endif; ?>
  </form>
</div>

<?php if (Auth::darf('courses.write')): ?>
<dialog class="modal" id="modal-lektion">
  <form method="post">
    <?= Auth::csrfFeld() ?>
    <input type="hidden" name="aktion" value="lektion">
    <input type="hidden" name="module_id" value="0">
    <div class="modal__kopf"><h2>Lektion</h2>
      <button type="button" class="rundknopf" data-modal-zu aria-label="Schließen"><?= Icon::svg('x', 17) ?></button></div>
    <div class="modal__koerper">
      <div class="feld"><label class="feld__label" for="lek-titel">Titel</label>
        <input class="eingabe" id="lek-titel" name="titel" required autofocus></div>
      <div class="feld-reihe feld-reihe--2">
        <div class="feld"><label class="feld__label" for="lek-art">Art</label>
          <select id="lek-art" name="art">
            <?php foreach (Courses::LEKTIONSARTEN as $k => [$n, $i]): ?>
              <option value="<?= $k ?>"><?= Util::h($n) ?></option>
            <?php endforeach; ?>
          </select></div>
        <div class="feld"><label class="feld__label" for="lek-dauer">Dauer (Min)</label>
          <input class="eingabe" id="lek-dauer" type="number" name="dauer_min" value="5" min="1"></div>
      </div>
      <div class="feld"><label class="feld__label" for="lek-video">Video-Adresse</label>
        <input class="eingabe" id="lek-video" type="url" name="video_url" placeholder="https://youtu.be/…"></div>
      <div class="feld"><label class="feld__label" for="lek-inhalt">Inhalt</label>
        <textarea class="eingabe" id="lek-inhalt" name="inhalt" rows="5" data-waechst
                  placeholder="Text der Lektion oder Begleitnotizen zum Video."></textarea></div>
    </div>
    <div class="modal__fuss"><div class="fueller"></div>
      <button type="button" class="btn" data-modal-zu>Abbrechen</button>
      <button type="submit" class="btn btn--primaer">Hinzufügen</button></div>
  </form>
</dialog>

<dialog class="modal modal--schmal" id="modal-einschreiben">
  <form method="post">
    <?= Auth::csrfFeld() ?>
    <input type="hidden" name="aktion" value="einschreiben">
    <div class="modal__kopf"><h2>Teilnehmer einschreiben</h2>
      <button type="button" class="rundknopf" data-modal-zu aria-label="Schließen"><?= Icon::svg('x', 17) ?></button></div>
    <div class="modal__koerper">
      <div class="feld"><label class="feld__label" for="ein-kunde">Kunde</label>
        <select id="ein-kunde" name="customer_id" required>
          <?php foreach (Tenant::all('customers', "status = 'aktiv'", [], 'nachname') as $k): ?>
            <option value="<?= (int) $k['id'] ?>"><?= Util::h(Customers::name($k)) ?></option>
          <?php endforeach; ?>
        </select></div>
      <p class="klein gedimmt">Der Kunde bekommt eine E-Mail mit dem Zugang zum Kurs.</p>
    </div>
    <div class="modal__fuss"><div class="fueller"></div>
      <button type="button" class="btn" data-modal-zu>Abbrechen</button>
      <button type="submit" class="btn btn--primaer">Einschreiben</button></div>
  </form>
</dialog>
<?php endif; ?>

<?php require __DIR__ . '/partials/fuss.php'; ?>
