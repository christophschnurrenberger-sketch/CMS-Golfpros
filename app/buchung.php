<?php
/**
 * Einzelner Termin – anlegen, ansehen, umbuchen, absagen.
 *
 * Beim Anlegen werden die freien Zeiten aus der Verfügbarkeitsrechnung
 * angeboten statt eines leeren Datumsfeldes: Wer selbst eine Uhrzeit
 * eintippt, bucht früher oder später doppelt.
 */
require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/partials/helfer.php';

Auth::fordern('modul.bookings');

$istNeu = App::get('id') === 'neu';
$id     = $istNeu ? 0 : App::getInt('id');
$termin = $istNeu ? null : Tenant::find('bookings', $id);

if (!$istNeu && !$termin) {
    App::melden('Dieser Termin wurde nicht gefunden.', 'fehler');
    App::weiter('/app/kalender.php');
}

if (App::istPost()) {
    Auth::csrfFordern();
    Auth::fordern('bookings.write');
    $aktion = App::aktion();

    if ($aktion === 'anlegen') {
        [$neueId, $fehler] = Bookings::buchen([
            'service_id'  => App::postInt('service_id'),
            'customer_id' => App::postInt('customer_id'),
            'trainer_id'  => App::postInt('trainer_id'),
            'location_id' => App::postInt('location_id'),
            /*
             * Das Feld heisst 'datum' - unter diesem Namen liest die Seite
             * den Tag auch beim Neuladen aus der Adresse.
             *
             * Eine von Hand eingetragene Uhrzeit sticht die angebotene:
             * Wer sie ausfuellt, meint sie auch. Die Kollisionspruefung
             * laeuft trotzdem, ausser der Haken darunter ist gesetzt.
             */
            'start'       => Util::zeitpunkt(
                App::post('datum'),
                App::post('zeit_frei') !== '' ? App::post('zeit_frei') : App::post('start_zeit')
            ),
            'titel'       => App::post('titel'),
            'notiz'       => App::post('notiz'),
            'interne_notiz' => App::post('interne_notiz'),
            'preis_cent'  => Util::centAus(App::post('preis')),
            'customer_package_id' => App::postInt('customer_package_id'),
            'bezahlt'     => App::postBool('bezahlt') ? 1 : 0,
            'ohne_pruefung' => App::postBool('ohne_pruefung'),
        ]);
        if ($neueId > 0) {
            App::melden('Termin angelegt.');
            App::weiter('/app/buchung.php?id=' . $neueId);
        }
        App::melden($fehler ?: 'Der Termin konnte nicht angelegt werden.', 'fehler');
    }

    if ($aktion === 'speichern' && $id > 0) {
        Tenant::update('bookings', $id, [
            'titel' => App::post('titel'),
            'status' => App::post('status'),
            'notiz' => App::post('notiz'),
            'interne_notiz' => App::post('interne_notiz'),
            'bezahlt' => App::postBool('bezahlt') ? 1 : 0,
            'preis_cent' => Util::centAus(App::post('preis')),
        ]);
        App::melden('Termin gespeichert.');
        App::weiter('/app/buchung.php?id=' . $id);
    }

    if ($aktion === 'umbuchen' && $id > 0) {
        [$ok, $fehler] = Bookings::umbuchen($id,
            Util::zeitpunkt(App::post('neu_datum'), App::post('neu_zeit')),
            App::postInt('trainer_id'));
        App::melden($ok ? 'Termin verschoben. Der Kunde wurde nicht automatisch informiert.'
                        : $fehler, $ok ? 'erfolg' : 'fehler');
        App::weiter('/app/buchung.php?id=' . $id);
    }

    if ($aktion === 'absagen' && $id > 0) {
        Bookings::absagen($id, App::post('grund'), App::postBool('einheit_zurueck'));
        App::melden('Termin abgesagt.');
        App::weiter('/app/buchung.php?id=' . $id);
    }

    if ($aktion === 'loeschen' && $id > 0) {
        Tenant::delete('bookings', $id);
        Audit::schreiben('geloescht', 'booking', $id);
        App::melden('Termin gelöscht.');
        App::weiter('/app/kalender.php');
    }
}

$leistungen = Tenant::all('services', 'aktiv = 1', [], 'position, name');
$trainer    = Auth::trainer();
$orte       = Tenant::all('locations', 'aktiv = 1', [], 'name');

/* ------------------------------------------------------------- Anlegen */

if ($istNeu) {
    Auth::fordern('bookings.write');
    $kundeId   = App::getInt('kunde');
    $serviceId = App::getInt('leistung', (int) ($leistungen[0]['id'] ?? 0));
    $service   = $serviceId > 0 ? Tenant::find('services', $serviceId) : null;

    /*
     * Ohne ausdruecklichen Wunsch nicht stur auf heute stellen.
     *
     * Jede Leistung hat eine Vorlauffrist - zwoelf Stunden sind die
     * Voreinstellung -, und die sperrt den heutigen Tag fast immer
     * vollstaendig. Wer "Termin anlegen" klickt, sah deshalb jedes Mal
     * "An diesem Tag ist keine Zeit frei" und hielt die Funktion fuer
     * kaputt. Gesucht wird darum der naechste Tag, an dem wirklich etwas
     * frei ist; gefunden sich in zwei Wochen keiner, bleibt es bei heute.
     */
    $zeitVorgabe = App::get('zeit');
    $gewuenscht  = App::get('datum');
    $datum      = $gewuenscht !== '' ? $gewuenscht : Util::heute();
    $frei       = $serviceId > 0 ? Bookings::freieZeiten($serviceId, $datum) : [];

    if ($gewuenscht === '' && $frei === [] && $serviceId > 0) {
        for ($i = 1; $i <= 14; $i++) {
            $probe = date('Y-m-d', strtotime('+' . $i . ' days'));
            $treffer = Bookings::freieZeiten($serviceId, $probe);
            if ($treffer !== []) {
                $datum = $probe;
                $frei  = $treffer;
                break;
            }
        }
    }
    $pakete    = $kundeId > 0 ? Commerce::offenePakete($kundeId) : [];

    $titel = 'Termin anlegen';
    $unter = 'Leistung und Tag wählen – die freien Zeiten rechnet das System aus deinen Arbeitszeiten.';
    $brotkrumen = [['Kalender', '/app/kalender.php'], ['Neuer Termin', null]];
    require __DIR__ . '/partials/kopf.php';
    ?>
    <?php /*
     * Warum hier eine eigene Funktion steht statt des naheliegenden
     * this.form.submit() mit method=get:
     *
     * Ein GET-Absenden ersetzt die gesamte Abfrage in der Adresse durch die
     * Formularfelder. Damit ging id=neu verloren - es steht nur in der
     * Adresse, nicht im Formular -, und die Seite hielt den Aufruf fuer
     * einen bestehenden Termin mit der Nummer 0. Heraus kam "Dieser Termin
     * wurde nicht gefunden", sobald jemand einen anderen Tag waehlte.
     *
     * Dazu kommt: Die Seite liest ihre Vorgaben unter anderen Namen, als
     * das Formular seine Felder nennt - leistung gegen service_id, kunde
     * gegen customer_id. Ein blindes Absenden kann das nicht treffen.
     *
     * Und das CSRF-Merkmal landete in der Adresszeile und damit im Verlauf
     * des Browsers. Das gehoert in keinen Verlauf.
     */ ?>
    <script>
    function terminNeuLaden(f) {
      var u = new URL(location.pathname, location.origin);
      u.searchParams.set('id', 'neu');
      u.searchParams.set('leistung', f.service_id.value);
      u.searchParams.set('datum', f.datum.value);
      if (f.customer_id && f.customer_id.value !== '0') {
        u.searchParams.set('kunde', f.customer_id.value);
      }
      /*
       * Eine schon gewaehlte Uhrzeit mitnehmen. Ohne das loescht ein
       * Wechsel des Kunden - der nur die Pakete nachladen soll - die
       * Uhrzeit, die jemand gerade von Hand eingetippt hat.
       */
      var gewaehlt = f.zeit_frei && f.zeit_frei.value ? f.zeit_frei.value : '';
      if (!gewaehlt && f.start_zeit) {
        var radios = f.start_zeit.length ? f.start_zeit : [f.start_zeit];
        for (var i = 0; i < radios.length; i++) {
          if (radios[i].checked) { gewaehlt = radios[i].value; break; }
        }
      }
      if (gewaehlt) { u.searchParams.set('zeit', gewaehlt); }
      location.href = u.toString();
    }
    </script>

    <form method="post" class="raster raster--haupt-neben" style="align-items:start">
      <?= Auth::csrfFeld() ?>
      <input type="hidden" name="aktion" value="anlegen">

      <div class="karte">
        <div class="karte__koerper">
          <div class="feld-reihe feld-reihe--2">
            <div class="feld">
              <label class="feld__label" for="service_id">Leistung</label>
              <select id="service_id" name="service_id" onchange="terminNeuLaden(this.form)">
                <?php foreach ($leistungen as $s): ?>
                  <option value="<?= (int) $s['id'] ?>"<?= $serviceId === (int) $s['id'] ? ' selected' : '' ?>>
                    <?= Util::h((string) $s['name']) ?> · <?= (int) $s['dauer_min'] ?> Min ·
                    <?= Util::h(Util::geldKurz((int) $s['preis_cent'])) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="feld">
              <label class="feld__label" for="start_datum">Tag</label>
              <input class="eingabe" id="start_datum" type="date" name="datum"
                     value="<?= Util::attr($datum) ?>" min="<?= Util::attr(Util::heute()) ?>"
                     onchange="terminNeuLaden(this.form)">
            </div>
          </div>

          <div class="feld">
            <label class="feld__label">Freie Zeiten am <?= Util::h(Util::datumLang($datum)) ?></label>
            <?php if ($frei === []): ?>
              <div class="hinweis hinweis--warnung">
                <?= Icon::svg('alert', 17) ?>
                <div class="hinweis__text">
                  An diesem Tag ist keine Zeit frei. Das kann an Arbeitszeiten, Urlaub, der
                  Vorlauffrist von <?= (int) ($service['vorlauf_stunden'] ?? 12) ?> Stunden oder
                  an bestehenden Terminen liegen.
                  <div class="mt-2">
                    <a class="btn btn--klein" href="<?= Util::attr(App::url('/app/verfuegbarkeit.php')) ?>">
                      Arbeitszeiten prüfen</a>
                  </div>
                </div>
              </div>
            <?php else: ?>
              <div class="reihe reihe--eng reihe--umbruch" id="zeiten">
                <?php
                /* Kommt eine Uhrzeit aus der Adresse, ist sie gemeint - sonst
                   der erste Vorschlag. */
                $zeitPasst = false;
                foreach ($frei as $z) {
                    if (date('H:i', strtotime($z['start'])) === $zeitVorgabe) { $zeitPasst = true; break; }
                }
                foreach ($frei as $i => $z):
                    $diese = date('H:i', strtotime($z['start']));
                    $aktiv = $zeitPasst ? ($diese === $zeitVorgabe) : ($i === 0); ?>
                  <label class="pille pille--gross <?= $aktiv ? 'pille--marke' : 'pille--offen' ?>"
                         style="cursor:pointer">
                    <input type="radio" name="start_zeit" value="<?= Util::attr($diese) ?>"
                           <?= $aktiv ? 'checked' : '' ?> style="display:none"
                           onchange="document.querySelectorAll('#zeiten label').forEach(l=>l.className='pille pille--gross pille--offen');this.parentNode.className='pille pille--gross pille--marke';document.getElementById('trainer_id').value=this.dataset.trainer||document.getElementById('trainer_id').value"
                           data-trainer="<?= (int) $z['trainer_id'] ?>">
                    <?= Util::h($diese) ?>
                  </label>
                <?php endforeach; ?>
              </div>
              <div class="feld__hinweis"><?= count($frei) ?> freie Zeiten ·
                <?= (int) ($service['dauer_min'] ?? 60) ?> Minuten je Termin</div>
            <?php endif; ?>
          </div>

          <?php /*
           * Die angebotenen Zeiten sind der bequeme Weg, aber sie duerfen
           * nicht der einzige sein. Ein Kunde ruft an und will Samstag um
           * sieben - die Vorlauffrist und die Arbeitszeiten gelten fuer die
           * Online-Buchung, nicht fuer den Pro an seinem eigenen Kalender.
           * Ohne dieses Feld liess sich an einem ausgebuchten Tag gar kein
           * Termin anlegen, auch nicht mit dem Haken weiter unten.
           *
           * Die Kollisionspruefung laeuft weiter: Wer sich mit sich selbst
           * ueberschneidet, bekommt es gesagt und muss es ausdruecklich
           * wollen.
           */ ?>
          <div class="feld">
            <label class="feld__label" for="zeit_frei">
              <?= $frei === [] ? 'Uhrzeit eintragen' : 'Oder eine andere Uhrzeit' ?>
            </label>
            <?php /* Nur zurueckschreiben, wenn die Zeit nicht schon oben
                      angekreuzt steht - sonst stuenden beide da. */ ?>
            <input class="eingabe" id="zeit_frei" type="time" name="zeit_frei"
                   value="<?= Util::attr(($zeitPasst ?? false) ? '' : $zeitVorgabe) ?>"
                   step="300" style="max-width:150px">
            <div class="feld__hinweis">
              <?= $frei === []
                    ? 'An diesem Tag bietet das System nichts an – hier trägst du die Zeit selbst ein.'
                    : 'Ausgefüllt sticht dieses Feld die Auswahl darüber.' ?>
            </div>
          </div>

          <div class="feld">
            <label class="feld__label" for="customer_id">Kunde</label>
            <select id="customer_id" name="customer_id" onchange="terminNeuLaden(this.form)">
              <option value="0">Ohne Kunde (Blocker, interner Termin)</option>
              <?php foreach (Tenant::all('customers', "status = 'aktiv'", [], 'nachname, vorname') as $k): ?>
                <option value="<?= (int) $k['id'] ?>"<?= $kundeId === (int) $k['id'] ? ' selected' : '' ?>>
                  <?= Util::h(Customers::name($k)) ?><?= (string) $k['hcp'] !== '' ? ' · HCP ' . Util::h(Util::hcp((string) $k['hcp'])) : '' ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <?php if ($pakete !== []): ?>
            <div class="feld">
              <label class="feld__label" for="customer_package_id">Aus einem Paket abbuchen</label>
              <select id="customer_package_id" name="customer_package_id">
                <option value="0">Nein, einzeln abrechnen</option>
                <?php foreach ($pakete as $pk): ?>
                  <option value="<?= (int) $pk['id'] ?>">
                    <?= Util::h((string) $pk['name']) ?> ·
                    <?= (int) $pk['einheiten_gesamt'] - (int) $pk['einheiten_genutzt'] ?> von
                    <?= (int) $pk['einheiten_gesamt'] ?> frei</option>
                <?php endforeach; ?>
              </select>
            </div>
          <?php endif; ?>

          <div class="feld">
            <label class="feld__label" for="notiz">Notiz für den Kunden</label>
            <textarea class="eingabe" id="notiz" name="notiz" rows="2" data-waechst
                      placeholder="Bitte Regenjacke mitbringen."></textarea>
          </div>
          <div class="feld">
            <label class="feld__label" for="interne_notiz">Interne Notiz</label>
            <textarea class="eingabe" id="interne_notiz" name="interne_notiz" rows="2" data-waechst
                      placeholder="Nur für dich sichtbar."></textarea>
          </div>
        </div>
        <div class="karte__fuss">
          <div class="fueller"></div>
          <a class="btn" href="<?= Util::attr(App::url('/app/kalender.php')) ?>">Abbrechen</a>
          <?php /*
           * Frueher war der Knopf gesperrt, sobald das System nichts
           * anbot. Zusammen mit dem Feld fuer eine eigene Uhrzeit waere
           * das eine Tuer mit Schloss und ohne Klinke: Der Pro traegt eine
           * Zeit ein und kann sie nicht abschicken.
           */ ?>
          <button class="btn btn--primaer" type="submit">Termin buchen</button>
        </div>
      </div>

      <div class="karte">
        <div class="karte__kopf"><h3>Einzelheiten</h3></div>
        <div class="karte__koerper">
          <div class="feld">
            <label class="feld__label" for="titel">Titel</label>
            <input class="eingabe" id="titel" name="titel" value="<?= Util::attr((string) ($service['name'] ?? '')) ?>">
          </div>
          <?php if (count($trainer) > 1): ?>
          <div class="feld">
            <label class="feld__label" for="trainer_id">Trainer</label>
            <select id="trainer_id" name="trainer_id">
              <?php foreach ($trainer as $t): ?>
                <option value="<?= (int) $t['id'] ?>"<?= (int) $t['id'] === Auth::id() ? ' selected' : '' ?>>
                  <?= Util::h((string) $t['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <?php else: ?>
            <input type="hidden" id="trainer_id" name="trainer_id" value="<?= Auth::id() ?>">
          <?php endif; ?>
          <?php if (count($orte) > 1): ?>
          <div class="feld">
            <label class="feld__label" for="location_id">Standort</label>
            <select id="location_id" name="location_id">
              <?php foreach ($orte as $o): ?>
                <option value="<?= (int) $o['id'] ?>"<?= (int) ($service['location_id'] ?? 0) === (int) $o['id'] ? ' selected' : '' ?>>
                  <?= Util::h((string) $o['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <?php endif; ?>
          <div class="feld">
            <label class="feld__label" for="preis">Preis</label>
            <div class="eingabe-gruppe">
              <input class="eingabe" id="preis" name="preis"
                     value="<?= Util::attr(number_format((int) ($service['preis_cent'] ?? 0) / 100, 2, ',', '')) ?>">
              <span class="eingabe-gruppe__anhang"><?= Util::h(Tenant::waehrung()) ?></span>
            </div>
          </div>
          <label class="haken">
            <input type="checkbox" name="bezahlt" value="1">
            <span class="haken__text">Bereits bezahlt</span>
          </label>
          <label class="haken mt-3">
            <input type="checkbox" name="ohne_pruefung" value="1">
            <span class="haken__text">Überschneidung erlauben
              <span class="haken__hinweis">Für Gruppentermine oder wenn zwei Dinge parallel laufen.</span></span>
          </label>
        </div>
      </div>
    </form>
    <?php
    require __DIR__ . '/partials/fuss.php';
    exit;
}

/* -------------------------------------------------------------- Ansicht */

$kunde   = (int) $termin['customer_id'] > 0 ? Tenant::find('customers', (int) $termin['customer_id']) : null;
$service = (int) $termin['service_id'] > 0 ? Tenant::find('services', (int) $termin['service_id']) : null;
$ort     = (int) $termin['location_id'] > 0 ? Tenant::find('locations', (int) $termin['location_id']) : null;
$teilnehmer = Tenant::all('booking_participants', 'booking_id = :b', ['b' => $id]);
$vergangen  = strtotime((string) $termin['ende']) < time();

$titel = (string) $termin['titel'];
$unter = Util::datumLang((string) $termin['start']) . ', ' . Util::uhrzeit((string) $termin['start'])
       . '–' . Util::uhrzeit((string) $termin['ende']) . ' Uhr';
$brotkrumen = [['Kalender', '/app/kalender.php'], [Util::datum((string) $termin['start']), null]];

$aktionen = '';
if (Auth::darf('bookings.write') && (string) $termin['status'] !== 'abgesagt') {
    $aktionen .= '<button class="btn" data-modal-auf="modal-umbuchen">' . Icon::svg('repeat', 15) . ' Umbuchen</button>'
               . '<button class="btn" data-modal-auf="modal-absagen">' . Icon::svg('x', 15) . ' Absagen</button>';
}
require __DIR__ . '/partials/kopf.php';
?>

<div class="raster raster--haupt-neben">
  <div class="stapel">
    <?php if ((string) $termin['status'] === 'abgesagt'): ?>
      <div class="hinweis hinweis--gefahr">
        <?= Icon::svg('alert', 17) ?>
        <div class="hinweis__text">
          <span class="hinweis__titel">Abgesagt am <?= Util::h(Util::datum((string) $termin['abgesagt_am'])) ?></span>
          <?= Util::h((string) $termin['abgesagt_grund']) ?: 'Kein Grund angegeben.' ?>
        </div>
      </div>
    <?php endif; ?>

    <form method="post" class="karte">
      <?= Auth::csrfFeld() ?>
      <input type="hidden" name="aktion" value="speichern">
      <div class="karte__kopf"><h2>Termin</h2>
        <div class="fueller"></div>
        <?= pille(Bookings::statusName((string) $termin['status']), Bookings::statusFarbe((string) $termin['status'])) ?>
      </div>
      <div class="karte__koerper">
        <div class="feld-reihe feld-reihe--2">
          <div class="feld">
            <label class="feld__label" for="titel">Titel</label>
            <input class="eingabe" id="titel" name="titel" value="<?= Util::attr((string) $termin['titel']) ?>"
                   <?= Auth::darf('bookings.write') ? '' : 'disabled' ?>>
          </div>
          <div class="feld">
            <label class="feld__label" for="status">Status</label>
            <select id="status" name="status" <?= Auth::darf('bookings.write') ? '' : 'disabled' ?>>
              <?php foreach (Bookings::STATUS as $k => [$name, $farbe]): ?>
                <option value="<?= $k ?>"<?= (string) $termin['status'] === $k ? ' selected' : '' ?>>
                  <?= Util::h($name) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <div class="feld">
          <label class="feld__label" for="notiz">Notiz für den Kunden</label>
          <textarea class="eingabe" id="notiz" name="notiz" rows="2" data-waechst
                    <?= Auth::darf('bookings.write') ? '' : 'disabled' ?>><?= Util::h((string) $termin['notiz']) ?></textarea>
        </div>
        <div class="feld">
          <label class="feld__label" for="interne_notiz">Interne Notiz</label>
          <textarea class="eingabe" id="interne_notiz" name="interne_notiz" rows="3" data-waechst
                    placeholder="Was lief gut, woran wird als Nächstes gearbeitet?"
                    <?= Auth::darf('bookings.write') ? '' : 'disabled' ?>><?= Util::h((string) $termin['interne_notiz']) ?></textarea>
        </div>
        <div class="feld-reihe feld-reihe--2">
          <div class="feld">
            <label class="feld__label" for="preis">Preis</label>
            <div class="eingabe-gruppe">
              <input class="eingabe" id="preis" name="preis"
                     value="<?= Util::attr(number_format((int) $termin['preis_cent'] / 100, 2, ',', '')) ?>"
                     <?= Auth::darf('bookings.write') ? '' : 'disabled' ?>>
              <span class="eingabe-gruppe__anhang"><?= Util::h(Tenant::waehrung()) ?></span>
            </div>
          </div>
          <div class="feld" style="align-self:end">
            <label class="haken" style="height:36px;align-items:center">
              <input type="checkbox" name="bezahlt" value="1"<?= (int) $termin['bezahlt'] === 1 ? ' checked' : '' ?>
                     <?= Auth::darf('bookings.write') ? '' : 'disabled' ?>>
              <span class="haken__text">Bezahlt</span>
            </label>
          </div>
        </div>
      </div>
      <?php if (Auth::darf('bookings.write')): ?>
      <div class="karte__fuss">
        <div class="fueller"></div>
        <button class="btn btn--primaer" type="submit">Speichern</button>
      </div>
      <?php endif; ?>
    </form>

    <?php if ($teilnehmer !== []): ?>
    <div class="karte">
      <div class="karte__kopf"><h3>Teilnehmer</h3>
        <span class="pille"><?= count($teilnehmer) ?> / <?= (int) ($service['kapazitaet'] ?? 0) ?></span>
      </div>
      <div class="karte__koerper karte__koerper--eng">
        <div class="stapel stapel--eng">
          <?php foreach ($teilnehmer as $tn): ?>
            <div class="reihe" style="padding:5px">
              <?= person(Customers::nameVonId((int) $tn['customer_id']),
                    ['url' => '/app/kunde.php?id=' . (int) $tn['customer_id']]) ?>
              <div class="fueller"></div>
              <?= pille((int) $tn['bezahlt'] === 1 ? 'bezahlt' : 'offen',
                    (int) $tn['bezahlt'] === 1 ? 'erfolg' : 'warnung') ?>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
    <?php endif; ?>
  </div>

  <div class="stapel">
    <?php if ($kunde): ?>
    <div class="karte">
      <div class="karte__kopf"><h3>Kunde</h3></div>
      <div class="karte__koerper">
        <?= person(Customers::name($kunde), [
              'unter' => (string) $kunde['hcp'] !== '' ? 'HCP ' . Util::hcp((string) $kunde['hcp']) : (string) $kunde['email'],
              'url' => '/app/kunde.php?id=' . (int) $kunde['id'], 'gross' => true,
            ]) ?>
        <div class="stapel stapel--eng mt-4">
          <?php if ((string) $kunde['telefon'] !== ''): ?>
            <a class="btn btn--voll" href="tel:<?= Util::attr((string) $kunde['telefon']) ?>">
              <?= Icon::svg('phone', 15) ?> <?= Util::h((string) $kunde['telefon']) ?></a>
          <?php endif; ?>
          <?php if ((string) $kunde['email'] !== ''): ?>
            <a class="btn btn--voll" href="mailto:<?= Util::attr((string) $kunde['email']) ?>">
              <?= Icon::svg('mail', 15) ?> E-Mail schreiben</a>
          <?php endif; ?>
          <a class="btn btn--voll" href="<?= Util::attr(App::url('/app/kunde.php?id=' . (int) $kunde['id'])) ?>">
            <?= Icon::svg('user', 15) ?> Zur Kundenakte</a>
        </div>
        <?php if ((string) $kunde['ziele'] !== ''): ?>
          <div class="hinweis hinweis--still mt-4">
            <?= Icon::svg('target', 16) ?>
            <div class="hinweis__text"><?= Util::h((string) $kunde['ziele']) ?></div>
          </div>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>

    <div class="karte">
      <div class="karte__kopf"><h3>Eckdaten</h3></div>
      <div class="karte__koerper karte__koerper--eng">
        <div class="stapel stapel--eng">
          <?php foreach ([
            ['Leistung', (string) ($service['name'] ?? '—')],
            ['Dauer', ((strtotime((string) $termin['ende']) - strtotime((string) $termin['start'])) / 60) . ' Minuten'],
            ['Trainer', Auth::trainerName((int) $termin['trainer_id'])],
            ['Standort', (string) ($ort['name'] ?? '—')],
            ['Gebucht über', ['backend' => 'Backend', 'website' => 'Website', 'portal' => 'Kundenzugang',
                              'automation' => 'Automation'][(string) $termin['quelle']] ?? (string) $termin['quelle']],
            ['Gebucht am', Util::datumZeit((string) $termin['erstellt'])],
            ['Aus Paket', (int) $termin['customer_package_id'] > 0 ? 'ja' : 'nein'],
          ] as [$label, $wert]): ?>
            <div class="reihe" style="font-size:13px">
              <span class="gedimmt"><?= Util::h($label) ?></span>
              <div class="fueller"></div>
              <span class="halbfett" style="text-align:right"><?= Util::h($wert) ?></span>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

    <?php if (Auth::darf('bookings.write')): ?>
    <form method="post" data-bestaetigen="Diesen Termin endgültig löschen? Eine Absage bleibt in der Historie – Löschen nicht.">
      <?= Auth::csrfFeld() ?>
      <input type="hidden" name="aktion" value="loeschen">
      <button class="btn btn--voll" type="submit" style="color:var(--gefahr)">
        <?= Icon::svg('trash', 15) ?> Termin löschen</button>
    </form>
    <?php endif; ?>
  </div>
</div>

<?php if (Auth::darf('bookings.write')): ?>
<dialog class="modal modal--schmal" id="modal-umbuchen">
  <form method="post">
    <?= Auth::csrfFeld() ?>
    <input type="hidden" name="aktion" value="umbuchen">
    <div class="modal__kopf"><h2>Termin verschieben</h2>
      <button type="button" class="rundknopf" data-modal-zu aria-label="Schließen"><?= Icon::svg('x', 17) ?></button></div>
    <div class="modal__koerper">
      <div class="feld-reihe feld-reihe--2">
        <div class="feld"><label class="feld__label" for="u-datum">Neuer Tag</label>
          <input class="eingabe" id="u-datum" type="date" name="neu_datum"
                 value="<?= Util::attr(date('Y-m-d', strtotime((string) $termin['start']))) ?>"></div>
        <div class="feld"><label class="feld__label" for="u-zeit">Uhrzeit</label>
          <input class="eingabe" id="u-zeit" type="time" name="neu_zeit"
                 value="<?= Util::attr(date('H:i', strtotime((string) $termin['start']))) ?>"></div>
      </div>
      <?php if (count($trainer) > 1): ?>
      <div class="feld"><label class="feld__label" for="u-trainer">Trainer</label>
        <select id="u-trainer" name="trainer_id">
          <?php foreach ($trainer as $t): ?>
            <option value="<?= (int) $t['id'] ?>"<?= (int) $termin['trainer_id'] === (int) $t['id'] ? ' selected' : '' ?>>
              <?= Util::h((string) $t['name']) ?></option>
          <?php endforeach; ?>
        </select></div>
      <?php endif; ?>
      <p class="klein gedimmt">Der Kunde wird nicht automatisch informiert – schreib ihm kurz.</p>
    </div>
    <div class="modal__fuss"><div class="fueller"></div>
      <button type="button" class="btn" data-modal-zu>Abbrechen</button>
      <button type="submit" class="btn btn--primaer">Verschieben</button></div>
  </form>
</dialog>

<dialog class="modal modal--schmal" id="modal-absagen">
  <form method="post">
    <?= Auth::csrfFeld() ?>
    <input type="hidden" name="aktion" value="absagen">
    <div class="modal__kopf"><h2>Termin absagen</h2>
      <button type="button" class="rundknopf" data-modal-zu aria-label="Schließen"><?= Icon::svg('x', 17) ?></button></div>
    <div class="modal__koerper">
      <div class="feld"><label class="feld__label" for="a-grund">Grund</label>
        <input class="eingabe" id="a-grund" name="grund" placeholder="Kunde krank, Wetter, kurzfristig verhindert"></div>
      <?php if ((int) $termin['customer_package_id'] > 0): ?>
        <label class="haken">
          <input type="checkbox" name="einheit_zurueck" value="1" checked>
          <span class="haken__text">Paketeinheit gutschreiben
            <span class="haken__hinweis">Bei rechtzeitiger Absage üblich. Ohne Haken bleibt die Einheit verbraucht.</span></span>
        </label>
      <?php endif; ?>
    </div>
    <div class="modal__fuss"><div class="fueller"></div>
      <button type="button" class="btn" data-modal-zu>Zurück</button>
      <button type="submit" class="btn btn--gefahr">Absagen</button></div>
  </form>
</dialog>
<?php endif; ?>

<?php require __DIR__ . '/partials/fuss.php'; ?>
