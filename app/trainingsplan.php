<?php
/**
 * Trainingsplan-Baukasten.
 *
 * Der KI-Vorschlag landet als **Entwurf** auf dieser Seite, nicht beim
 * Kunden. Erst wenn der Trainer ihn geprüft, geändert und zugewiesen hat,
 * geht er raus. Das ist die Regel für alle KI-Funktionen im System, und
 * hier ist sie am wichtigsten: Ein Trainingsplan ist eine fachliche
 * Aussage, für die der Pro geradesteht.
 */
require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/partials/helfer.php';

Auth::fordern('modul.training');

$istNeu = App::get('id') === 'neu';
$id     = $istNeu ? 0 : App::getInt('id');
$plan   = $istNeu ? null : Tenant::find('training_plans', $id);

if (!$istNeu && !$plan) {
    App::melden('Dieser Trainingsplan wurde nicht gefunden.', 'fehler');
    App::weiter('/app/training.php');
}

if (App::istPost()) {
    Auth::csrfFordern();
    Auth::fordern('training.write');
    $aktion = App::aktion();

    if ($aktion === 'ki_entwurf') {
        $vorschlag = KI::trainingsplan(App::post('vorgabe'), App::postInt('wochen', 4), App::postInt('pro_woche', 2));
        if ($vorschlag['einheiten'] === []) {
            App::melden($vorschlag['hinweis'], 'fehler');
            App::weiter('/app/uebungen.php');
        }
        $neuId = Training::planSpeichern([
            'name' => $vorschlag['name'], 'ziel' => $vorschlag['ziel'],
            'wochen' => $vorschlag['wochen'], 'einheiten_woche' => App::postInt('pro_woche', 2),
            'niveau' => $vorschlag['niveau'] ?? 'mittel',
            'customer_id' => App::postInt('customer_id'),
            'beschreibung' => App::post('vorgabe'),
            'status' => 'entwurf', 'quelle' => 'ai',
        ]);
        foreach ($vorschlag['einheiten'] as $woche => $einheiten) {
            foreach ($einheiten as $nummer => $uebungen) {
                foreach ($uebungen as $u) {
                    Training::positionHinzufuegen($neuId, $u + ['woche' => $woche, 'einheit' => $nummer]);
                }
            }
        }
        App::melden('Entwurf erstellt. Bitte prüfen und anpassen, bevor du ihn zuweist.', 'info');
        App::weiter('/app/trainingsplan.php?id=' . $neuId);
    }

    if ($aktion === 'speichern') {
        $daten = [
            'name' => App::post('name'), 'beschreibung' => App::post('beschreibung'),
            'ziel' => App::post('ziel'), 'wochen' => max(1, App::postInt('wochen', 4)),
            'einheiten_woche' => max(1, App::postInt('einheiten_woche', 2)),
            'niveau' => App::post('niveau', 'anfaenger'),
            'customer_id' => App::postInt('customer_id'),
            'vorlage' => App::postInt('customer_id') === 0 ? 1 : 0,
            'status' => App::post('status', 'entwurf'),
        ];
        if ($daten['name'] === '') {
            App::melden('Der Plan braucht einen Namen.', 'fehler');
        } else {
            $neuId = Training::planSpeichern($daten, $id);
            App::melden($id > 0 ? 'Plan gespeichert.' : 'Plan angelegt.');
            App::weiter('/app/trainingsplan.php?id=' . $neuId);
        }
    }

    if ($aktion === 'position' && $id > 0) {
        Training::positionHinzufuegen($id, [
            'exercise_id' => App::postInt('exercise_id'),
            'woche' => App::postInt('woche', 1), 'einheit' => App::postInt('einheit', 1),
        ]);
        App::weiter('/app/trainingsplan.php?id=' . $id);
    }

    if ($aktion === 'position_loeschen' && $id > 0) {
        Tenant::deleteWhere('plan_items', 'id = :i AND plan_id = :p',
            ['i' => App::postInt('item_id'), 'p' => $id]);
        App::weiter('/app/trainingsplan.php?id=' . $id);
    }

    if ($aktion === 'zuweisen' && $id > 0) {
        $kundeId = App::postInt('customer_id');
        if ($kundeId > 0) {
            Training::zuweisen($id, $kundeId);
            Tenant::update('training_plans', $id, ['customer_id' => $kundeId, 'vorlage' => 0, 'status' => 'aktiv']);
            App::melden('Plan zugewiesen. Der Kunde hat eine E-Mail bekommen.');
        }
        App::weiter('/app/trainingsplan.php?id=' . $id);
    }

    if ($aktion === 'loeschen' && $id > 0) {
        Tenant::deleteWhere('plan_items', 'plan_id = :p', ['p' => $id]);
        Tenant::deleteWhere('plan_assignments', 'plan_id = :p', ['p' => $id]);
        Tenant::delete('training_plans', $id);
        App::melden('Plan gelöscht.');
        App::weiter('/app/training.php');
    }
}

/* --------------------------------------------------------------- Neu -- */

if ($istNeu) {
    Auth::fordern('training.write');
    $kundeId = App::getInt('kunde');
    $kunde = $kundeId > 0 ? Tenant::find('customers', $kundeId) : null;
    $mitKI = App::get('ki') === '1';

    $titel = 'Trainingsplan erstellen';
    $unter = $kunde ? 'Für ' . Customers::name($kunde) : 'Als Vorlage für mehrere Kunden';
    $brotkrumen = [['Training', '/app/training.php'], ['Neuer Plan', null]];
    require __DIR__ . '/partials/kopf.php';
    ?>
    <div class="raster raster--2" style="align-items:start">
      <form method="post" class="karte">
        <?= Auth::csrfFeld() ?>
        <input type="hidden" name="aktion" value="ki_entwurf">
        <input type="hidden" name="customer_id" value="<?= $kundeId ?>">
        <div class="karte__kopf">
          <h2><?= Icon::svg('ai', 17) ?> Mit KI entwerfen</h2>
          <?php if (!KI::verfuegbar()): ?>
            <div class="fueller"></div><span class="pille pille--offen">regelbasiert</span>
          <?php endif; ?>
        </div>
        <div class="karte__koerper">
          <div class="feld">
            <label class="feld__label" for="vorgabe">Beschreib den Kunden und das Ziel</label>
            <textarea class="eingabe" id="vorgabe" name="vorgabe" rows="4" data-waechst required
              placeholder="Kunde HCP 32, zweimal Training pro Woche, große Schwäche beim Putting."><?= $kunde
                ? Util::h('Kunde ' . ((string) $kunde['hcp'] !== '' ? 'HCP ' . Util::hcp((string) $kunde['hcp']) : '')
                  . ', ' . ((string) $kunde['ziele'] !== '' ? 'Ziel: ' . $kunde['ziele'] : ''))
                : '' ?></textarea>
            <div class="feld__hinweis">Je konkreter die Schwäche, desto passender der Vorschlag.</div>
          </div>
          <div class="feld-reihe feld-reihe--2">
            <div class="feld"><label class="feld__label" for="wochen">Wochen</label>
              <input class="eingabe" id="wochen" type="number" name="wochen" value="4" min="1" max="16"></div>
            <div class="feld"><label class="feld__label" for="pro_woche">Einheiten pro Woche</label>
              <input class="eingabe" id="pro_woche" type="number" name="pro_woche" value="2" min="1" max="7"></div>
          </div>
          <div class="hinweis hinweis--still">
            <?= Icon::svg('info', 16) ?>
            <div class="hinweis__text">
              <?= KI::verfuegbar()
                ? 'Der Vorschlag entsteht aus deiner Übungsbibliothek und der Beschreibung.'
                : 'Ohne hinterlegten KI-Zugang stellt das System den Plan regelbasiert aus deiner '
                  . 'Übungsbibliothek zusammen – gewichtet nach der genannten Schwäche.' ?>
              Er landet als <strong>Entwurf</strong> bei dir, nicht beim Kunden.
            </div>
          </div>
        </div>
        <div class="karte__fuss"><div class="fueller"></div>
          <button class="btn btn--primaer" type="submit"><?= Icon::svg('wand', 15) ?> Entwurf erstellen</button></div>
      </form>

      <form method="post" class="karte">
        <?= Auth::csrfFeld() ?>
        <input type="hidden" name="aktion" value="speichern">
        <input type="hidden" name="customer_id" value="<?= $kundeId ?>">
        <div class="karte__kopf"><h2>Von Hand anlegen</h2></div>
        <div class="karte__koerper">
          <div class="feld"><label class="feld__label" for="n-name">Name</label>
            <input class="eingabe" id="n-name" name="name" required placeholder="4 Wochen Kurzspiel"></div>
          <div class="feld"><label class="feld__label" for="n-ziel">Ziel</label>
            <input class="eingabe" id="n-ziel" name="ziel" placeholder="Scrambling-Quote von 20 auf 35 Prozent"></div>
          <div class="feld-reihe feld-reihe--3">
            <div class="feld"><label class="feld__label" for="n-wochen">Wochen</label>
              <input class="eingabe" id="n-wochen" type="number" name="wochen" value="4" min="1" max="26"></div>
            <div class="feld"><label class="feld__label" for="n-ew">Einheiten/Woche</label>
              <input class="eingabe" id="n-ew" type="number" name="einheiten_woche" value="2" min="1" max="7"></div>
            <div class="feld"><label class="feld__label" for="n-niveau">Niveau</label>
              <select id="n-niveau" name="niveau">
                <option value="anfaenger">Anfänger</option>
                <option value="mittel">Fortgeschritten</option>
                <option value="fortgeschritten">Turnierspieler</option>
              </select></div>
          </div>
        </div>
        <div class="karte__fuss"><div class="fueller"></div>
          <button class="btn btn--primaer" type="submit">Anlegen</button></div>
      </form>
    </div>
    <?php
    require __DIR__ . '/partials/fuss.php';
    exit;
}

/* ------------------------------------------------------------ Ansicht */

$aufbau = Training::planNachWochen($id);
$kunde  = (int) $plan['customer_id'] > 0 ? Tenant::find('customers', (int) $plan['customer_id']) : null;
$dauer  = Training::planDauer($id);
$anzahl = Tenant::count('plan_items', 'plan_id = :p', ['p' => $id]);
$uebungen = Training::uebungen();

$titel = (string) $plan['name'];
$unter = (int) $plan['wochen'] . ' Wochen · ' . $anzahl . ' Übungen · '
       . Util::zahl($dauer / 60, 1) . ' Stunden Trainingszeit'
       . ($kunde ? ' · für ' . Util::h(Customers::name($kunde)) : ' · Vorlage');
$brotkrumen = [['Training', '/app/training.php'], [(string) $plan['name'], null]];

$aktionen = '<a class="btn" href="' . App::url('/app/pdf.php?art=trainingsplan&id=' . $id) . '" target="_blank" rel="noopener">'
          . Icon::svg('download', 15) . ' PDF</a>';
if (Auth::darf('training.write')) {
    $aktionen .= $kunde
        ? '<a class="btn btn--primaer" href="' . App::url('/app/kunde.php?id=' . (int) $kunde['id'] . '#training') . '">'
          . Icon::svg('user', 15) . ' Zur Kundenakte</a>'
        : '<button class="btn btn--primaer" data-modal-auf="modal-zuweisen">'
          . Icon::svg('send', 15) . ' Kunden zuweisen</button>';
}
require __DIR__ . '/partials/kopf.php';
?>

<?php if ((string) $plan['status'] === 'entwurf'): ?>
  <div class="hinweis hinweis--warnung mb-4">
    <?= Icon::svg('alert', 17) ?>
    <div class="hinweis__text">
      <span class="hinweis__titel">Entwurf<?= (string) $plan['quelle'] === 'ai' ? ' aus dem KI-Vorschlag' : '' ?></span>
      Der Kunde sieht diesen Plan noch nicht. Prüfe die Übungen, passe an, was nicht passt,
      und weise ihn dann zu.
    </div>
  </div>
<?php endif; ?>

<div class="raster raster--haupt-neben">
  <div class="stapel">
    <?php for ($woche = 1; $woche <= (int) $plan['wochen']; $woche++): ?>
      <div class="karte">
        <div class="karte__kopf">
          <h2>Woche <?= $woche ?></h2>
          <?php
          $wochenDauer = 0;
          foreach ($aufbau[$woche] ?? [] as $einheiten) {
              foreach ($einheiten as $u) { $wochenDauer += (int) $u['dauer_min']; }
          } ?>
          <div class="fueller"></div>
          <span class="klein gedimmt"><?= $wochenDauer ?> Minuten</span>
        </div>
        <div class="karte__koerper">
          <?php for ($einheit = 1; $einheit <= (int) $plan['einheiten_woche']; $einheit++):
            $liste = $aufbau[$woche][$einheit] ?? []; ?>
            <div class="mb-5">
              <div class="reihe reihe--eng mb-3">
                <span class="versal gedimmt-2">Einheit <?= $einheit ?></span>
                <div class="fueller"></div>
                <?php if (Auth::darf('training.write')): ?>
                  <button class="btn btn--klein btn--geist" data-modal-auf="modal-position"
                          data-setzwoche="<?= $woche ?>" data-setzeinheit="<?= $einheit ?>"
                          data-modal-titel="Übung zu Woche <?= $woche ?>, Einheit <?= $einheit ?>">
                    <?= Icon::svg('plus', 13) ?> Übung</button>
                <?php endif; ?>
              </div>
              <?php if ($liste === []): ?>
                <p class="klein gedimmt-2" style="padding:var(--r2) 0">Noch keine Übung.</p>
              <?php else: ?>
                <div class="stapel stapel--eng">
                  <?php foreach ($liste as $u): ?>
                    <div class="kachel-link" style="align-items:center">
                      <span class="kachel-link__symbol" style="width:30px;height:30px;border-radius:9px">
                        <?= Icon::svg('dumbbell', 15) ?></span>
                      <div style="flex:1;min-width:0">
                        <div class="kachel-link__titel"><?= Util::h((string) $u['titel']) ?></div>
                        <div class="kachel-link__text">
                          <?= (int) $u['dauer_min'] ?> Min
                          <?php if ((string) $u['wiederholungen'] !== ''): ?>
                            · <?= Util::h((string) $u['wiederholungen']) ?>
                          <?php endif; ?>
                        </div>
                        <?php if ((string) $u['notiz'] !== ''): ?>
                          <div class="klein gedimmt mt-2" style="line-height:1.55">
                            <?= Util::h(Util::kuerzen((string) $u['notiz'], 180)) ?></div>
                        <?php endif; ?>
                      </div>
                      <?php if ($u['erledigt']): ?>
                        <span class="tipp" data-tipp="Erledigt am <?= Util::attr(Util::datum((string) $u['erledigt'])) ?>"
                              style="color:var(--erfolg)"><?= Icon::svg('check', 16) ?></span>
                      <?php endif; ?>
                      <?php if (Auth::darf('training.write')): ?>
                        <form method="post" data-bestaetigen="Übung aus dem Plan entfernen?">
                          <?= Auth::csrfFeld() ?>
                          <input type="hidden" name="aktion" value="position_loeschen">
                          <input type="hidden" name="item_id" value="<?= (int) $u['id'] ?>">
                          <button class="btn btn--klein btn--geist btn--nur-symbol" type="submit"
                                  aria-label="Entfernen"><?= Icon::svg('x', 14) ?></button>
                        </form>
                      <?php endif; ?>
                    </div>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>
            </div>
          <?php endfor; ?>
        </div>
      </div>
    <?php endfor; ?>
  </div>

  <div class="stapel">
    <?php if ($kunde): ?>
    <div class="karte">
      <div class="karte__kopf"><h3>Zugewiesen an</h3></div>
      <div class="karte__koerper">
        <?= person(Customers::name($kunde), [
              'unter' => (string) $kunde['hcp'] !== '' ? 'HCP ' . Util::hcp((string) $kunde['hcp']) : '',
              'url' => '/app/kunde.php?id=' . (int) $kunde['id'], 'gross' => true]) ?>
        <?php $fortschritt = Training::fortschritt($id, (int) $kunde['id']); ?>
        <div class="mt-4">
          <div class="reihe klein mb-2"><span class="gedimmt">Fortschritt</span>
            <div class="fueller"></div><span class="halbfett"><?= $fortschritt ?> %</span></div>
          <?= balken($fortschritt) ?>
        </div>
      </div>
    </div>
    <?php endif; ?>

    <form method="post" class="karte">
      <?= Auth::csrfFeld() ?>
      <input type="hidden" name="aktion" value="speichern">
      <input type="hidden" name="customer_id" value="<?= (int) $plan['customer_id'] ?>">
      <div class="karte__kopf"><h3>Plan</h3></div>
      <div class="karte__koerper">
        <div class="feld"><label class="feld__label" for="p-name">Name</label>
          <input class="eingabe" id="p-name" name="name" value="<?= Util::attr((string) $plan['name']) ?>"></div>
        <div class="feld"><label class="feld__label" for="p-ziel">Ziel</label>
          <textarea class="eingabe" id="p-ziel" name="ziel" rows="2" data-waechst><?= Util::h((string) $plan['ziel']) ?></textarea></div>
        <div class="feld-reihe feld-reihe--2">
          <div class="feld"><label class="feld__label" for="p-wochen">Wochen</label>
            <input class="eingabe" id="p-wochen" type="number" name="wochen"
                   value="<?= (int) $plan['wochen'] ?>" min="1" max="26"></div>
          <div class="feld"><label class="feld__label" for="p-ew">Einheiten/Woche</label>
            <input class="eingabe" id="p-ew" type="number" name="einheiten_woche"
                   value="<?= (int) $plan['einheiten_woche'] ?>" min="1" max="7"></div>
        </div>
        <div class="feld"><label class="feld__label" for="p-status">Status</label>
          <select id="p-status" name="status">
            <?php foreach (['entwurf' => 'Entwurf', 'aktiv' => 'Aktiv', 'abgeschlossen' => 'Abgeschlossen'] as $k => $v): ?>
              <option value="<?= $k ?>"<?= (string) $plan['status'] === $k ? ' selected' : '' ?>><?= $v ?></option>
            <?php endforeach; ?>
          </select></div>
      </div>
      <?php if (Auth::darf('training.write')): ?>
        <div class="karte__fuss"><div class="fueller"></div>
          <button class="btn btn--primaer btn--klein" type="submit">Speichern</button></div>
      <?php endif; ?>
    </form>

    <?php if (Auth::darf('training.write')): ?>
    <form method="post" data-bestaetigen="Diesen Trainingsplan löschen?">
      <?= Auth::csrfFeld() ?>
      <input type="hidden" name="aktion" value="loeschen">
      <button class="btn btn--voll" type="submit" style="color:var(--gefahr)">
        <?= Icon::svg('trash', 15) ?> Plan löschen</button>
    </form>
    <?php endif; ?>
  </div>
</div>

<?php if (Auth::darf('training.write')): ?>
<dialog class="modal" id="modal-position">
  <form method="post">
    <?= Auth::csrfFeld() ?>
    <input type="hidden" name="aktion" value="position">
    <input type="hidden" name="woche" value="1">
    <input type="hidden" name="einheit" value="1">
    <div class="modal__kopf"><h2>Übung hinzufügen</h2>
      <button type="button" class="rundknopf" data-modal-zu aria-label="Schließen"><?= Icon::svg('x', 17) ?></button></div>
    <div class="modal__koerper">
      <div class="feld">
        <label class="feld__label" for="pos-uebung">Übung</label>
        <select id="pos-uebung" name="exercise_id" required size="12" style="height:auto">
          <?php $letzteKat = '';
          foreach ($uebungen as $u):
            if ((string) $u['kategorie'] !== $letzteKat):
              if ($letzteKat !== '') { echo '</optgroup>'; }
              $letzteKat = (string) $u['kategorie'];
              echo '<optgroup label="' . Util::attr(Training::KATEGORIEN[$letzteKat] ?? $letzteKat) . '">';
            endif; ?>
            <option value="<?= (int) $u['id'] ?>">
              <?= Util::h((string) $u['titel']) ?> · <?= (int) $u['dauer_min'] ?> Min</option>
          <?php endforeach;
          if ($letzteKat !== '') { echo '</optgroup>'; } ?>
        </select>
      </div>
      <p class="klein gedimmt">Fehlt eine Übung?
        <a href="<?= Util::attr(App::url('/app/uebungen.php')) ?>">In der Bibliothek anlegen</a>.</p>
    </div>
    <div class="modal__fuss"><div class="fueller"></div>
      <button type="button" class="btn" data-modal-zu>Abbrechen</button>
      <button type="submit" class="btn btn--primaer">Hinzufügen</button></div>
  </form>
</dialog>

<?php if (!$kunde): ?>
<dialog class="modal modal--schmal" id="modal-zuweisen">
  <form method="post">
    <?= Auth::csrfFeld() ?>
    <input type="hidden" name="aktion" value="zuweisen">
    <div class="modal__kopf"><h2>Plan zuweisen</h2>
      <button type="button" class="rundknopf" data-modal-zu aria-label="Schließen"><?= Icon::svg('x', 17) ?></button></div>
    <div class="modal__koerper">
      <div class="feld">
        <label class="feld__label" for="z-kunde">Kunde</label>
        <select id="z-kunde" name="customer_id" required>
          <?php foreach (Tenant::all('customers', 'status = "aktiv"', [], 'nachname, vorname') as $k): ?>
            <option value="<?= (int) $k['id'] ?>"><?= Util::h(Customers::name($k)) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="hinweis hinweis--still">
        <?= Icon::svg('mail', 16) ?>
        <div class="hinweis__text">Der Kunde bekommt eine E-Mail mit dem Link zu seinem Bereich,
          wo der Plan mit allen Übungen steht.</div>
      </div>
    </div>
    <div class="modal__fuss"><div class="fueller"></div>
      <button type="button" class="btn" data-modal-zu>Abbrechen</button>
      <button type="submit" class="btn btn--primaer">Zuweisen und senden</button></div>
  </form>
</dialog>
<?php endif; ?>
<?php endif; ?>

<?php require __DIR__ . '/partials/fuss.php'; ?>
