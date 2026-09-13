<?php
/**
 * Arbeitszeiten, Urlaub und Sperrzeiten.
 *
 * Die wichtigste Einstellung für die Online-Buchung: Ohne Arbeitszeiten
 * zeigt die Website keine freien Termine an – und die Auslastungsrechnung
 * hat keine Bezugsgröße.
 */
require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/partials/helfer.php';

Auth::fordern('modul.bookings');

$trainerId = App::getInt('trainer', Auth::id());
$tage = ['Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag', 'Samstag', 'Sonntag'];
$reihenfolge = [1, 2, 3, 4, 5, 6, 0];

if (App::istPost()) {
    Auth::csrfFordern();
    Auth::fordern('bookings.write');

    if (App::aktion() === 'zeiten') {
        $ziel = App::postInt('trainer_id', Auth::id());
        Tenant::deleteWhere('availability', 'user_id = :u', ['u' => $ziel]);
        foreach ($reihenfolge as $wt) {
            if (!App::postBool('aktiv_' . $wt)) {
                continue;
            }
            $von = Util::zeitZuMinuten(App::post('von_' . $wt, '09:00'));
            $bis = Util::zeitZuMinuten(App::post('bis_' . $wt, '18:00'));
            if ($bis <= $von) {
                continue;
            }
            Tenant::insert('availability', [
                'user_id' => $ziel, 'location_id' => App::postInt('location_id'),
                'wochentag' => $wt, 'von_min' => $von, 'bis_min' => $bis, 'aktiv' => 1,
            ]);
        }
        App::melden('Arbeitszeiten gespeichert.');
        App::weiter('/app/verfuegbarkeit.php?trainer=' . $ziel);
    }

    if (App::aktion() === 'frei') {
        Tenant::insert('time_off', [
            'user_id' => App::postInt('trainer_id'),
            'titel' => App::post('titel'),
            'art' => App::post('art', 'urlaub'),
            'von' => App::post('von') . ' 00:00:00',
            'bis' => App::post('bis') . ' 23:59:59',
            'ganztags' => 1,
        ]);
        App::melden('Eingetragen. In diesem Zeitraum sind keine Online-Buchungen mehr möglich.');
        App::weiter('/app/verfuegbarkeit.php?trainer=' . $trainerId);
    }

    if (App::aktion() === 'frei_loeschen') {
        Tenant::delete('time_off', App::postInt('id'));
        App::melden('Eintrag entfernt.');
        App::weiter('/app/verfuegbarkeit.php?trainer=' . $trainerId);
    }
}

$zeiten = [];
foreach (Tenant::all('availability', 'user_id = :u', ['u' => $trainerId]) as $z) {
    $zeiten[(int) $z['wochentag']] = $z;
}
$abwesend = Tenant::all('time_off', 'user_id = :u OR user_id = 0', ['u' => $trainerId], 'von');
$stunden = 0;
foreach ($zeiten as $z) {
    $stunden += ((int) $z['bis_min'] - (int) $z['von_min']) / 60;
}

$titel = 'Verfügbarkeit';
$unter = 'Arbeitszeiten, Urlaub und Sperrzeiten · ' . Util::zahl($stunden, 1) . ' Stunden pro Woche';
require __DIR__ . '/partials/kopf.php';
?>

<?php if (count(Auth::trainer()) > 1): ?>
<div class="reiter">
  <?php foreach (Auth::trainer() as $t): ?>
    <a class="reiter__teil<?= $trainerId === (int) $t['id'] ? ' ist-aktiv' : '' ?>"
       href="<?= Util::attr(App::url('/app/verfuegbarkeit.php?trainer=' . (int) $t['id'])) ?>">
      <?= Util::h((string) $t['name']) ?></a>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if ($zeiten === []): ?>
  <div class="hinweis hinweis--warnung mb-4">
    <?= Icon::svg('alert', 17) ?>
    <div class="hinweis__text">
      <span class="hinweis__titel">Noch keine Arbeitszeiten hinterlegt</span>
      Solange hier nichts steht, zeigt die Online-Buchung keine freien Termine an und die
      Auslastung lässt sich nicht berechnen.
    </div>
  </div>
<?php endif; ?>

<div class="raster raster--haupt-neben">
  <form method="post" class="karte">
    <?= Auth::csrfFeld() ?>
    <input type="hidden" name="aktion" value="zeiten">
    <input type="hidden" name="trainer_id" value="<?= $trainerId ?>">
    <div class="karte__kopf"><h2>Wöchentliche Arbeitszeiten</h2></div>
    <div class="karte__koerper">
      <?php foreach ($reihenfolge as $i => $wt):
        $z = $zeiten[$wt] ?? null; ?>
        <div class="reihe mb-3" style="gap:var(--r3)">
          <label class="schalter" style="min-width:150px">
            <input type="checkbox" name="aktiv_<?= $wt ?>" value="1"<?= $z ? ' checked' : '' ?>>
            <span class="schalter__spur"></span>
            <span style="font-size:13.5px;font-weight:520"><?= Util::h($tage[$i]) ?></span>
          </label>
          <input class="eingabe" type="time" name="von_<?= $wt ?>" style="width:120px"
                 value="<?= Util::attr($z ? Util::minutenZuZeit((int) $z['von_min']) : '09:00') ?>"
                 aria-label="Beginn <?= Util::attr($tage[$i]) ?>">
          <span class="gedimmt">bis</span>
          <input class="eingabe" type="time" name="bis_<?= $wt ?>" style="width:120px"
                 value="<?= Util::attr($z ? Util::minutenZuZeit((int) $z['bis_min']) : '18:00') ?>"
                 aria-label="Ende <?= Util::attr($tage[$i]) ?>">
          <div class="fueller"></div>
          <?php if ($z): ?>
            <span class="klein gedimmt umbruch-nein">
              <?= Util::zahl(((int) $z['bis_min'] - (int) $z['von_min']) / 60, 1) ?> h</span>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
      <div class="hinweis hinweis--still mt-4">
        <?= Icon::svg('info', 16) ?>
        <div class="hinweis__text">Trag die Zeiten ein, in denen du tatsächlich unterrichtest –
          nicht die Öffnungszeiten der Anlage. Sonst sieht die Auslastung nach Leerlauf aus,
          obwohl der Kalender voll ist.</div>
      </div>
    </div>
    <?php if (Auth::darf('bookings.write')): ?>
      <div class="karte__fuss"><div class="fueller"></div>
        <button class="btn btn--primaer" type="submit">Arbeitszeiten speichern</button></div>
    <?php endif; ?>
  </form>

  <div class="stapel">
    <div class="karte">
      <div class="karte__kopf"><h3>Urlaub und Sperrzeiten</h3>
        <div class="fueller"></div>
        <?php if (Auth::darf('bookings.write')): ?>
          <button class="btn btn--klein btn--geist" data-modal-auf="modal-frei"><?= Icon::svg('plus', 14) ?></button>
        <?php endif; ?>
      </div>
      <div class="karte__koerper karte__koerper--eng">
        <?php if ($abwesend === []): ?>
          <p class="klein gedimmt mitte" style="padding:var(--r3) 0">Nichts eingetragen.</p>
        <?php else: ?>
          <div class="stapel stapel--eng">
            <?php foreach ($abwesend as $a):
              $vorbei = strtotime((string) $a['bis']) < time(); ?>
              <div class="reihe" style="padding:6px;<?= $vorbei ? 'opacity:.5' : '' ?>">
                <?= Icon::svg((string) $a['art'] === 'urlaub' ? 'sun' : 'lock', 15) ?>
                <div style="flex:1;min-width:0">
                  <div class="klein halbfett"><?= Util::h((string) $a['titel'] ?: ucfirst((string) $a['art'])) ?></div>
                  <div class="winzig gedimmt-2">
                    <?= Util::h(Util::datum((string) $a['von'])) ?> – <?= Util::h(Util::datum((string) $a['bis'])) ?>
                  </div>
                </div>
                <?php if (Auth::darf('bookings.write')): ?>
                  <form method="post" data-bestaetigen="Eintrag entfernen?">
                    <?= Auth::csrfFeld() ?>
                    <input type="hidden" name="aktion" value="frei_loeschen">
                    <input type="hidden" name="id" value="<?= (int) $a['id'] ?>">
                    <button class="btn btn--klein btn--geist btn--nur-symbol" type="submit"
                            aria-label="Entfernen"><?= Icon::svg('x', 14) ?></button>
                  </form>
                <?php endif; ?>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <div class="karte">
      <div class="karte__kopf"><h3>So wirkt sich das aus</h3></div>
      <div class="karte__koerper">
        <div class="stapel stapel--eng klein">
          <div class="reihe"><span class="gedimmt">Arbeitszeit pro Woche</span><div class="fueller"></div>
            <span class="halbfett"><?= Util::zahl($stunden, 1) ?> Stunden</span></div>
          <div class="reihe"><span class="gedimmt">Auslastung diese Woche</span><div class="fueller"></div>
            <span class="halbfett"><?php
              $a = Bookings::auslastung(date('Y-m-d', strtotime('monday this week')),
                                        date('Y-m-d', strtotime('sunday this week')), $trainerId);
              echo $a === null ? '—' : Util::prozent($a); ?></span></div>
          <div class="reihe"><span class="gedimmt">Termine nächste 7 Tage</span><div class="fueller"></div>
            <span class="halbfett"><?= count(Bookings::zeitraum(Util::jetzt(),
                  date('Y-m-d H:i:s', strtotime('+7 days')), ['trainer' => $trainerId])) ?></span></div>
        </div>
      </div>
    </div>
  </div>
</div>

<?php if (Auth::darf('bookings.write')): ?>
<dialog class="modal modal--schmal" id="modal-frei">
  <form method="post">
    <?= Auth::csrfFeld() ?>
    <input type="hidden" name="aktion" value="frei">
    <input type="hidden" name="trainer_id" value="<?= $trainerId ?>">
    <div class="modal__kopf"><h2>Urlaub oder Sperrzeit</h2>
      <button type="button" class="rundknopf" data-modal-zu aria-label="Schließen"><?= Icon::svg('x', 17) ?></button></div>
    <div class="modal__koerper">
      <div class="feld"><label class="feld__label" for="f-titel">Was</label>
        <input class="eingabe" id="f-titel" name="titel" placeholder="Sommerurlaub" required></div>
      <div class="feld"><label class="feld__label" for="f-art">Art</label>
        <select id="f-art" name="art">
          <option value="urlaub">Urlaub</option>
          <option value="sperrzeit">Sperrzeit</option>
          <option value="feiertag">Feiertag</option>
        </select></div>
      <div class="feld-reihe feld-reihe--2">
        <div class="feld"><label class="feld__label" for="f-von">Von</label>
          <input class="eingabe" id="f-von" type="date" name="von" value="<?= Util::attr(Util::heute()) ?>" required></div>
        <div class="feld"><label class="feld__label" for="f-bis">Bis</label>
          <input class="eingabe" id="f-bis" type="date" name="bis" value="<?= Util::attr(Util::heute()) ?>" required></div>
      </div>
    </div>
    <div class="modal__fuss"><div class="fueller"></div>
      <button type="button" class="btn" data-modal-zu>Abbrechen</button>
      <button type="submit" class="btn btn--primaer">Eintragen</button></div>
  </form>
</dialog>
<?php endif; ?>

<?php require __DIR__ . '/partials/fuss.php'; ?>
