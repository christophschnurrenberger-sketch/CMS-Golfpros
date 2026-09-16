<?php
/** Eine Golfreise: alle Einzelheiten und die Anmeldungen dazu. */
require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/partials/helfer.php';

Auth::fordern('modul.travel');

$id    = App::getInt('id');
$reise = $id > 0 ? Tenant::find('trips', $id) : null;
if ($reise === null) {
    App::melden('Diese Reise gibt es nicht.', 'fehler');
    App::weiter('/app/reisen.php');
}

/*
 * Aus mehrzeiligen Feldern werden Listen: eine Zeile, ein Punkt.
 *
 * Für „was ist enthalten" wäre eine Tabelle mit eigener Zeile je Leistung
 * genauer – und im Backend eine Zumutung. Sieben Punkte tippt man in
 * sieben Zeilen, nicht über sieben Mal „Hinzufügen".
 */
$alsListe = static function (string $text): string {
    $zeilen = [];
    foreach (preg_split('/\r\n|\r|\n/', $text) ?: [] as $z) {
        $z = trim($z);
        if ($z !== '') {
            $zeilen[] = $z;
        }
    }
    return Util::json($zeilen);
};

if (App::istPost()) {
    Auth::csrfFordern();
    $aktion = App::aktion();

    if ($aktion === 'speichern') {
        Auth::fordern('travel.write');
        Trips::speichern([
            'titel'            => App::post('titel'),
            'ziel'             => App::post('ziel'),
            'land'             => App::post('land'),
            'hotel'            => App::post('hotel'),
            'kurztext'         => App::post('kurztext'),
            'beschreibung'     => App::post('beschreibung'),
            'leistungen'       => $alsListe(App::postRoh('leistungen')),
            'nicht_enthalten'  => $alsListe(App::postRoh('nicht_enthalten')),
            'bild'             => App::post('bild'),
            'start'            => App::post('start') . ' 00:00:00',
            'ende'             => App::post('ende') . ' 00:00:00',
            'anreise'          => array_key_exists(App::post('anreise'), Trips::ANREISE) ? App::post('anreise') : 'eigen',
            'abflug_ort'       => App::post('abflug_ort'),
            'preis_cent'       => Util::centAus(App::post('preis')),
            'ez_zuschlag_cent' => Util::centAus(App::post('ez_zuschlag')),
            'anzahlung_cent'   => Util::centAus(App::post('anzahlung')),
            'plaetze'          => max(1, App::postInt('plaetze', 12)),
            'mindest_teilnehmer' => max(0, App::postInt('mindest_teilnehmer')),
            'trainer_id'       => App::postInt('trainer_id'),
            'warteliste'       => App::postBool('warteliste') ? 1 : 0,
            'status'           => App::post('status', 'geplant'),
        ], $id);
        App::melden('Reise gespeichert.');
        App::weiter('/app/reise.php?id=' . $id);
    }

    if ($aktion === 'programm') {
        Auth::fordern('travel.write');
        $tage = [];
        $titelListe = App::postListe('tag_titel');
        $textListe  = App::postListe('tag_text');
        foreach ($titelListe as $i => $t) {
            $tage[] = ['titel' => trim((string) $t), 'text' => trim((string) ($textListe[$i] ?? ''))];
        }
        Trips::speichern(['programm' => Util::json($tage)], $id);
        App::melden('Programm gespeichert.');
        App::weiter('/app/reise.php?id=' . $id . '#programm');
    }

    if ($aktion === 'anmelden') {
        Auth::fordern('travel.write');
        [$neu, $meldung] = Trips::anmelden($id, [
            'name'         => App::post('name'),
            'email'        => App::post('email'),
            'telefon'      => App::post('telefon'),
            'zimmer'       => App::post('zimmer', 'dz'),
            'mitreisender' => App::post('mitreisender'),
            'customer_id'  => App::postInt('customer_id'),
        ]);
        App::melden($neu === 0 ? $meldung : ($meldung !== '' ? $meldung : 'Teilnehmer aufgenommen.'),
            $neu === 0 ? 'fehler' : 'erfolg');
        App::weiter('/app/reise.php?id=' . $id . '#teilnehmer');
    }

    if ($aktion === 'anmeldung_status') {
        Auth::fordern('travel.write');
        $anmeldung = Tenant::find('trip_signups', App::postInt('anmeldung_id'));
        /* Die Prüfung auf die Reise gehört dazu: Sonst ließe sich mit einer
           fremden Kennung eine Anmeldung einer anderen Reise umstellen. */
        if ($anmeldung !== null && (int) $anmeldung['trip_id'] === $id) {
            Trips::statusSetzen((int) $anmeldung['id'], App::post('status'));
            App::melden('Anmeldung aktualisiert.');
        }
        App::weiter('/app/reise.php?id=' . $id . '#teilnehmer');
    }

    if ($aktion === 'loeschen') {
        Auth::fordern('travel.delete');
        Tenant::delete('trips', $id);
        Audit::schreiben('geloescht', 'trip', $id, (string) $reise['titel']);
        App::melden('Reise gelöscht.');
        App::weiter('/app/reisen.php');
    }
}

$anmeldungen = Trips::anmeldungen($id);
$belegt = Trips::belegt($id);
$frei   = Trips::freiePlaetze($reise);
$fehlt  = Trips::fehlend($reise);
$programm = Trips::programm((string) $reise['programm']);
$darf = Auth::darf('travel.write');

$titel = (string) $reise['titel'];
$unter = trim((string) $reise['ziel'] . ' · ' . (string) $reise['land'], ' ·') . ' · '
       . Util::datum((string) $reise['start']) . '–' . Util::datum((string) $reise['ende'])
       . ' · ' . (int) $reise['naechte'] . ' Nächte';
$brotkrumen = [['Reisen', '/app/reisen.php'], [$titel, null]];
$aktionen = (string) $reise['status'] === Trips::OEFFENTLICH
    ? '<a class="btn" target="_blank" rel="noopener" href="'
      . Util::attr(Oeffentlich::url('/reise.php', ['r' => (string) $reise['slug']]))
      . '">' . Icon::svg('external', 15) . ' Auf der Website</a>'
    : '';
require __DIR__ . '/partials/kopf.php';
?>

<div class="raster raster--4 mb-5">
  <?= kennzahl('Angemeldet', $belegt . ' / ' . (int) $reise['plaetze'],
        ['icon' => 'customers', 'fuss' => $frei > 0 ? $frei . ' Plätze frei' : 'ausgebucht']) ?>
  <?= kennzahl('Gebucht', Util::geld(Trips::umsatz($id)),
        ['icon' => 'euro', 'fuss' => 'Summe der Anmeldungen']) ?>
  <?= kennzahl('Mindestzahl', (int) $reise['mindest_teilnehmer'] > 0 ? (int) $reise['mindest_teilnehmer'] : '—',
        ['icon' => 'target', 'fuss' => $fehlt > 0 ? 'noch ' . $fehlt . ' nötig' : 'erreicht']) ?>
  <?= kennzahl('Warteliste', (string) count(Trips::anmeldungen($id, 'warteliste')),
        ['icon' => 'list', 'fuss' => (int) $reise['warteliste'] === 1 ? 'wird geführt' : 'aus']) ?>
</div>

<div class="raster raster--haupt-neben">
  <div>
    <!-- ------------------------------------------------ Einzelheiten -->
    <form method="post" class="karte mb-5">
      <?= Auth::csrfFeld() ?>
      <input type="hidden" name="aktion" value="speichern">
      <div class="karte__kopf"><h2>Die Reise</h2></div>
      <div class="karte__koerper">
        <div class="feld"><label class="feld__label" for="t-titel">Titel</label>
          <input class="eingabe" id="t-titel" name="titel" required
                 value="<?= Util::attr((string) $reise['titel']) ?>"></div>

        <div class="feld-reihe feld-reihe--3">
          <div class="feld"><label class="feld__label" for="t-ziel">Ziel</label>
            <input class="eingabe" id="t-ziel" name="ziel" value="<?= Util::attr((string) $reise['ziel']) ?>"></div>
          <div class="feld"><label class="feld__label" for="t-land">Land</label>
            <input class="eingabe" id="t-land" name="land" value="<?= Util::attr((string) $reise['land']) ?>"></div>
          <div class="feld"><label class="feld__label" for="t-hotel">Hotel</label>
            <input class="eingabe" id="t-hotel" name="hotel" value="<?= Util::attr((string) $reise['hotel']) ?>"></div>
        </div>

        <div class="feld"><label class="feld__label" for="t-kurz">Ein Satz für die Übersicht</label>
          <input class="eingabe" id="t-kurz" name="kurztext" maxlength="255"
                 placeholder="Sieben Nächte direkt am Platz, vier Greenfees inklusive."
                 value="<?= Util::attr((string) $reise['kurztext']) ?>"></div>

        <div class="feld"><label class="feld__label" for="t-text">Beschreibung</label>
          <textarea class="eingabe" id="t-text" name="beschreibung" rows="6" data-waechst
                    placeholder="Wohin geht es, wie wird gespielt, für wen ist die Reise gedacht?"
          ><?= Util::h((string) $reise['beschreibung']) ?></textarea></div>

        <div class="feld-reihe feld-reihe--2">
          <div class="feld"><label class="feld__label" for="t-start">Anreise</label>
            <input class="eingabe" id="t-start" type="date" name="start" required
                   value="<?= Util::attr(substr((string) $reise['start'], 0, 10)) ?>"></div>
          <div class="feld"><label class="feld__label" for="t-ende">Abreise</label>
            <input class="eingabe" id="t-ende" type="date" name="ende" required
                   value="<?= Util::attr(substr((string) $reise['ende'], 0, 10)) ?>">
            <div class="feld__hinweis">Die Nächte rechnet das System daraus aus.</div></div>
        </div>

        <div class="feld-reihe feld-reihe--2">
          <div class="feld"><label class="feld__label" for="t-anreise">Anreiseart</label>
            <select class="eingabe" id="t-anreise" name="anreise">
              <?php foreach (Trips::ANREISE as $k => $n): ?>
                <option value="<?= Util::attr($k) ?>"<?= (string) $reise['anreise'] === $k ? ' selected' : '' ?>>
                  <?= Util::h($n) ?></option>
              <?php endforeach; ?>
            </select></div>
          <div class="feld"><label class="feld__label" for="t-abflug">Abflug- oder Abfahrtsort</label>
            <input class="eingabe" id="t-abflug" name="abflug_ort" placeholder="Stuttgart"
                   value="<?= Util::attr((string) $reise['abflug_ort']) ?>"></div>
        </div>

        <div class="feld-reihe feld-reihe--3">
          <div class="feld"><label class="feld__label" for="t-preis">Preis je Person im DZ</label>
            <div class="eingabe-gruppe">
              <input class="eingabe" id="t-preis" name="preis"
                     value="<?= Util::attr(number_format((int) $reise['preis_cent'] / 100, 2, ',', '')) ?>">
              <span class="eingabe-gruppe__anhang"><?= Util::h(Tenant::waehrung()) ?></span></div></div>
          <div class="feld"><label class="feld__label" for="t-ez">Einzelzimmerzuschlag</label>
            <div class="eingabe-gruppe">
              <input class="eingabe" id="t-ez" name="ez_zuschlag"
                     value="<?= Util::attr(number_format((int) $reise['ez_zuschlag_cent'] / 100, 2, ',', '')) ?>">
              <span class="eingabe-gruppe__anhang"><?= Util::h(Tenant::waehrung()) ?></span></div></div>
          <div class="feld"><label class="feld__label" for="t-anzahlung">Anzahlung</label>
            <div class="eingabe-gruppe">
              <input class="eingabe" id="t-anzahlung" name="anzahlung"
                     value="<?= Util::attr(number_format((int) $reise['anzahlung_cent'] / 100, 2, ',', '')) ?>">
              <span class="eingabe-gruppe__anhang"><?= Util::h(Tenant::waehrung()) ?></span></div>
            <div class="feld__hinweis">0 = keine</div></div>
        </div>

        <div class="feld-reihe feld-reihe--2">
          <div class="feld"><label class="feld__label" for="t-leistungen">Enthalten</label>
            <textarea class="eingabe" id="t-leistungen" name="leistungen" rows="6" data-waechst
                      placeholder="Eine Leistung je Zeile:&#10;7 Nächte im Doppelzimmer&#10;Halbpension&#10;4 Greenfees"
            ><?= Util::h(implode("\n", Trips::zeilen((string) $reise['leistungen']))) ?></textarea></div>
          <div class="feld"><label class="feld__label" for="t-nicht">Nicht enthalten</label>
            <textarea class="eingabe" id="t-nicht" name="nicht_enthalten" rows="6" data-waechst
                      placeholder="Eine Zeile je Punkt:&#10;Anreise&#10;Leihschläger&#10;Reiserücktrittsversicherung"
            ><?= Util::h(implode("\n", Trips::zeilen((string) $reise['nicht_enthalten']))) ?></textarea></div>
        </div>

        <div class="feld-reihe feld-reihe--3">
          <div class="feld"><label class="feld__label" for="t-plaetze">Plätze</label>
            <input class="eingabe" id="t-plaetze" type="number" name="plaetze" min="1"
                   value="<?= (int) $reise['plaetze'] ?>"></div>
          <div class="feld"><label class="feld__label" for="t-mindest">Mindestteilnehmer</label>
            <input class="eingabe" id="t-mindest" type="number" name="mindest_teilnehmer" min="0"
                   value="<?= (int) $reise['mindest_teilnehmer'] ?>"></div>
          <div class="feld"><label class="feld__label" for="t-status">Status</label>
            <select class="eingabe" id="t-status" name="status">
              <?php foreach (['geplant' => 'Geplant', 'veroeffentlicht' => 'Veröffentlicht',
                              'abgeschlossen' => 'Abgeschlossen', 'abgesagt' => 'Abgesagt'] as $k => $n): ?>
                <option value="<?= $k ?>"<?= (string) $reise['status'] === $k ? ' selected' : '' ?>>
                  <?= Util::h($n) ?></option>
              <?php endforeach; ?>
            </select>
            <div class="feld__hinweis">Nur Veröffentlichte stehen auf der Website.</div></div>
        </div>

        <div class="feld"><label class="feld__label" for="t-bild">Bild</label>
          <input class="eingabe" id="t-bild" name="bild" placeholder="/uploads/…"
                 value="<?= Util::attr((string) $reise['bild']) ?>"></div>

        <div class="feld-reihe feld-reihe--2">
          <div class="feld"><label class="feld__label" for="t-trainer">Begleitung</label>
            <select class="eingabe" id="t-trainer" name="trainer_id">
              <option value="0">— niemand eingetragen —</option>
              <?php foreach (Tenant::all('users', 'aktiv = 1', [], 'name') as $u): ?>
                <option value="<?= (int) $u['id'] ?>"<?= (int) $reise['trainer_id'] === (int) $u['id'] ? ' selected' : '' ?>>
                  <?= Util::h((string) $u['name']) ?></option>
              <?php endforeach; ?>
            </select></div>
          <div class="feld">
            <label class="haken" style="margin-top:28px">
              <input type="checkbox" name="warteliste" value="1"<?= (int) $reise['warteliste'] === 1 ? ' checked' : '' ?>>
              <span class="haken__text">Warteliste führen, wenn ausgebucht</span></label>
          </div>
        </div>
      </div>
      <?php if ($darf): ?>
        <div class="karte__fuss"><div class="fueller"></div>
          <button class="btn btn--primaer" type="submit">Speichern</button></div>
      <?php endif; ?>
    </form>

    <!-- ---------------------------------------------------- Programm -->
    <form method="post" class="karte mb-5" id="programm">
      <?= Auth::csrfFeld() ?>
      <input type="hidden" name="aktion" value="programm">
      <div class="karte__kopf"><h2>Tagesprogramm</h2>
        <div class="karte__kopf-aktionen"><span class="klein gedimmt">Leere Tage fallen weg</span></div></div>
      <div class="karte__koerper">
        <div class="stapel stapel--eng">
          <?php
          /* Immer eine leere Zeile mehr als belegt: So kommt ein Tag dazu,
             ohne dass irgendetwas nachgeladen werden muss. */
          $zeilen = $programm;
          $zeilen[] = ['titel' => '', 'text' => ''];
          foreach ($zeilen as $nr => $tag): ?>
            <div class="feld-reihe feld-reihe--2">
              <div class="feld" style="flex:0 1 220px">
                <label class="feld__label" for="tag-t-<?= $nr ?>">Tag <?= $nr + 1 ?></label>
                <input class="eingabe" id="tag-t-<?= $nr ?>" name="tag_titel[]"
                       placeholder="Anreise und Einspielen"
                       value="<?= Util::attr((string) $tag['titel']) ?>"></div>
              <div class="feld">
                <label class="feld__label" for="tag-x-<?= $nr ?>">Was passiert</label>
                <input class="eingabe" id="tag-x-<?= $nr ?>" name="tag_text[]"
                       placeholder="Transfer zum Hotel, 9 Löcher am Nachmittag"
                       value="<?= Util::attr((string) $tag['text']) ?>"></div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
      <?php if ($darf): ?>
        <div class="karte__fuss"><div class="fueller"></div>
          <button class="btn" type="submit">Programm speichern</button></div>
      <?php endif; ?>
    </form>
  </div>

  <!-- -------------------------------------------------- Teilnehmer -->
  <div>
    <div class="karte mb-5" id="teilnehmer">
      <div class="karte__kopf"><h2>Teilnehmer</h2>
        <div class="karte__kopf-aktionen">
          <?php if ($darf): ?>
            <button class="btn btn--klein btn--geist" data-modal-auf="modal-teilnehmer"
                    aria-label="Teilnehmer aufnehmen"><?= Icon::svg('plus', 14) ?></button>
          <?php endif; ?>
        </div></div>
      <div class="karte__koerper">
        <?php if ($anmeldungen === []): ?>
          <p class="klein gedimmt">Noch niemand angemeldet.</p>
        <?php else: ?>
          <div class="stapel stapel--eng">
            <?php foreach ($anmeldungen as $a): ?>
              <div class="karte"><div class="karte__koerper karte__koerper--eng">
                <div class="reihe reihe--zwischen mb-2">
                  <span class="halbfett"><?= Util::h((string) $a['name']) ?></span>
                  <?= pille((string) $a['status'],
                        (string) $a['status'] === 'bestaetigt' ? 'erfolg'
                        : ((string) $a['status'] === 'storniert' ? 'gefahr'
                        : ((string) $a['status'] === 'warteliste' ? 'warnung' : 'offen'))) ?>
                </div>
                <div class="klein gedimmt">
                  <?= Util::h(Trips::ZIMMER[(string) $a['zimmer']] ?? (string) $a['zimmer']) ?>
                  · <?= Util::h(Util::geldKurz((int) $a['preis_cent'])) ?>
                  <?php if ((string) $a['mitreisender'] !== ''): ?>
                    · mit <?= Util::h((string) $a['mitreisender']) ?>
                  <?php endif; ?>
                </div>
                <div class="winzig gedimmt mt-2"><?= Util::h((string) $a['email']) ?>
                  <?php if ((string) $a['telefon'] !== ''): ?> · <?= Util::h((string) $a['telefon']) ?><?php endif; ?>
                </div>
                <?php if ((string) $a['notiz'] !== ''): ?>
                  <p class="klein mt-2"><?= Util::h((string) $a['notiz']) ?></p>
                <?php endif; ?>
                <?php if ($darf): ?>
                  <form method="post" class="reihe reihe--eng mt-3">
                    <?= Auth::csrfFeld() ?>
                    <input type="hidden" name="aktion" value="anmeldung_status">
                    <input type="hidden" name="anmeldung_id" value="<?= (int) $a['id'] ?>">
                    <select class="eingabe" name="status" onchange="this.form.submit()">
                      <?php foreach (['angemeldet' => 'Angemeldet', 'bestaetigt' => 'Bestätigt',
                                      'warteliste' => 'Warteliste', 'storniert' => 'Storniert'] as $k => $n): ?>
                        <option value="<?= $k ?>"<?= (string) $a['status'] === $k ? ' selected' : '' ?>>
                          <?= Util::h($n) ?></option>
                      <?php endforeach; ?>
                    </select>
                    <noscript><button class="btn btn--klein" type="submit">Setzen</button></noscript>
                  </form>
                <?php endif; ?>
              </div></div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <?php if (Auth::darf('travel.delete')): ?>
      <form method="post" data-bestaetigen="Diese Reise mit allen Anmeldungen wirklich löschen?">
        <?= Auth::csrfFeld() ?>
        <input type="hidden" name="aktion" value="loeschen">
        <button class="btn btn--gefahr btn--klein" type="submit">
          <?= Icon::svg('trash', 14) ?> Reise löschen</button>
      </form>
    <?php endif; ?>
  </div>
</div>

<?php if ($darf): ?>
<dialog class="modal modal--schmal" id="modal-teilnehmer">
  <form method="post">
    <?= Auth::csrfFeld() ?>
    <input type="hidden" name="aktion" value="anmelden">
    <div class="modal__kopf"><h2>Teilnehmer aufnehmen</h2>
      <button type="button" class="rundknopf" data-modal-zu aria-label="Schließen"><?= Icon::svg('x', 17) ?></button></div>
    <div class="modal__koerper">
      <div class="feld"><label class="feld__label" for="tn-name">Name</label>
        <input class="eingabe" id="tn-name" name="name" required autofocus></div>
      <div class="feld-reihe feld-reihe--2">
        <div class="feld"><label class="feld__label" for="tn-mail">E-Mail</label>
          <input class="eingabe" id="tn-mail" type="email" name="email" required></div>
        <div class="feld"><label class="feld__label" for="tn-tel">Telefon</label>
          <input class="eingabe" id="tn-tel" type="tel" name="telefon"></div>
      </div>
      <div class="feld-reihe feld-reihe--2">
        <div class="feld"><label class="feld__label" for="tn-zimmer">Zimmer</label>
          <select class="eingabe" id="tn-zimmer" name="zimmer">
            <?php foreach (Trips::ZIMMER as $k => $n): ?>
              <option value="<?= $k ?>"><?= Util::h($n) ?><?php
                if ($k === 'ez' && (int) $reise['ez_zuschlag_cent'] > 0): ?>
                (+<?= Util::h(Util::geldKurz((int) $reise['ez_zuschlag_cent'])) ?>)<?php endif; ?></option>
            <?php endforeach; ?>
          </select></div>
        <div class="feld"><label class="feld__label" for="tn-mit">Mitreisende Person</label>
          <input class="eingabe" id="tn-mit" name="mitreisender"></div>
      </div>
    </div>
    <div class="modal__fuss"><div class="fueller"></div>
      <button type="button" class="btn" data-modal-zu>Abbrechen</button>
      <button type="submit" class="btn btn--primaer">Aufnehmen</button></div>
  </form>
</dialog>
<?php endif; ?>

<?php require __DIR__ . '/partials/fuss.php'; ?>
