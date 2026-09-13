<?php
/**
 * Dashboard – die Steuerungsoberfläche.
 *
 * Aufbau nach einer Frage: Was muss der Pro wissen, wenn er morgens die
 * Seite öffnet? Zuerst die Zahlen des Geschäfts, dann die Empfehlungen,
 * dann der heutige Tag. Alles Weitere ist einen Klick entfernt.
 */
require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/partials/helfer.php';

Auth::fordern('modul.dashboard');

/*
 * Ein frisch angelegter Workspace hat noch keine Zahlen, die ein Dashboard
 * zeigen koennte. Statt leerer Kacheln fuehrt der Weg zuerst durch die
 * Einrichtung - sie endet mit einer veroeffentlichten Website.
 */
if ((int) (Tenant::workspace()['onboarding_schritt'] ?? 0) < 9
    && Tenant::count('customers') === 0
    && Auth::darf('settings.allgemein')) {
    App::weiter('/app/onboarding.php');
}

Wartung::laufen();

$heute     = Util::heute();
$monatVon  = date('Y-m-01');
$jahrVon   = date('Y-01-01');
$istTrainer = Auth::rolle() === 'trainer';
$nurMeine  = $istTrainer ? Auth::id() : 0;

$umsatzHeute = Commerce::umsatz($heute, $heute);
$umsatzMonat = Commerce::umsatz($monatVon, $heute);
$umsatzJahr  = Commerce::umsatz($jahrVon, $heute);
$umsatzVormonat = Commerce::umsatz(date('Y-m-01', strtotime('-1 month')), date('Y-m-t', strtotime('-1 month')));
$umsatzVorjahr  = Commerce::umsatz(date('Y-01-01', strtotime('-1 year')), date('Y-m-d', strtotime('-1 year')));

$termineHeute = Bookings::heute($nurMeine);
$kommende     = Bookings::kommende(6, $nurMeine);
$auslastung   = Bookings::auslastung(date('Y-m-d', strtotime('monday this week')),
                                     date('Y-m-d', strtotime('sunday this week')), $nurMeine);

$neueLeads   = Tenant::all('leads', 'stufe = "neu"', [], 'erstellt DESC', 5);
$neueKunden  = Tenant::all('customers', 'erstellt >= :seit', ['seit' => date('Y-m-d', strtotime('-30 days'))], 'id DESC', 5);
$offeneRechnungen = Tenant::all('invoices', 'status IN ("offen","ueberfaellig")', [], 'faellig', 5);
$aufgaben    = Tenant::all('tasks', 'erledigt IS NULL AND (user_id = 0 OR user_id = :u)',
                           ['u' => Auth::id()], 'faellig', 6);

$empfehlungen = Empfehlungen::fuerDashboard(5);
$verlauf = Analytics::umsatzVerlauf(date('Y-m-d', strtotime('-29 days')), $heute);

$besucher30 = Analytics::besucher(date('Y-m-d', strtotime('-29 days')), $heute);
$leads30    = Tenant::count('leads', 'erstellt >= :seit', ['seit' => date('Y-m-d', strtotime('-29 days'))]);
$conversion = $besucher30 > 0 ? $leads30 / $besucher30 * 100 : 0.0;

$aktivePakete = Tenant::count('customer_packages', 'status = "aktiv" AND einheiten_genutzt < einheiten_gesamt');
$offeneEinheiten = Tenant::sum('customer_packages', 'einheiten_gesamt - einheiten_genutzt',
    'status = "aktiv" AND einheiten_genutzt < einheiten_gesamt');

[$inaktivWo, $inaktivP] = Segments::bedingung(['bedingungen' => [
    ['feld' => 'tage_seit_termin', 'op' => '>', 'wert' => '60'],
    ['feld' => 'status', 'op' => '=', 'wert' => 'aktiv'],
]]);
$inaktiv = $inaktivWo !== '' ? Tenant::count('customers', $inaktivWo, $inaktivP) : 0;

$stunde = (int) date('G');
$gruss = $stunde < 11 ? 'Guten Morgen' : ($stunde < 18 ? 'Hallo' : 'Guten Abend');
$vorname = explode(' ', Auth::name())[0];

$titel = $gruss . ', ' . $vorname;
$unter = Util::h(Util::datumLang($heute)) . ' · '
       . ($termineHeute === []
          ? 'Heute steht kein Termin an.'
          : count($termineHeute) . ' Termin' . (count($termineHeute) === 1 ? '' : 'e') . ' heute');
$aktionen = '<a class="btn" href="' . App::url('/app/kalender.php') . '">' . Icon::svg('calendar', 15) . ' Kalender</a>'
          . (Auth::darf('bookings.write')
             ? '<a class="btn btn--primaer" href="' . App::url('/app/buchung.php?id=neu') . '">'
               . Icon::svg('plus', 15) . ' Termin anlegen</a>' : '');

require __DIR__ . '/partials/kopf.php';
?>

<div class="raster raster--4 mb-5">
  <?= kennzahl('Umsatz heute', Util::geld($umsatzHeute), [
        'icon' => 'euro',
        'fuss' => $umsatzHeute > 0 ? 'bezahlt eingegangen' : 'noch nichts verbucht',
        'url' => Auth::darf('analytics.view') ? '/app/auswertung.php?zeit=heute' : '',
      ]) ?>
  <?= kennzahl('Umsatz diesen Monat', Util::geld($umsatzMonat), [
        'icon' => 'trend-up',
        'delta' => Util::wachstum((float) $umsatzMonat, (float) $umsatzVormonat),
        'fuss' => 'vs. Vormonat',
        'kurve' => $verlauf['werte'],
        'url' => Auth::darf('analytics.view') ? '/app/auswertung.php?zeit=monat' : '',
      ]) ?>
  <?= kennzahl('Umsatz dieses Jahr', Util::geld($umsatzJahr), [
        'icon' => 'analytics',
        'delta' => Util::wachstum((float) $umsatzJahr, (float) $umsatzVorjahr),
        'fuss' => 'vs. Vorjahr',
        'url' => Auth::darf('analytics.view') ? '/app/auswertung.php?zeit=jahr' : '',
      ]) ?>
  <?= kennzahl('Auslastung diese Woche',
        $auslastung === null ? '—' : Util::prozent($auslastung),
        [
          'icon' => 'activity',
          'fuss' => $auslastung === null
            ? '<a href="' . App::url('/app/verfuegbarkeit.php') . '">Arbeitszeiten fehlen</a>'
            : count(Bookings::zeitraum(date('Y-m-d', strtotime('monday this week')) . ' 00:00:00',
                    date('Y-m-d', strtotime('sunday this week')) . ' 23:59:59')) . ' Termine',
          'url' => '/app/kalender.php',
        ]) ?>
</div>

<div class="raster raster--haupt-neben">
  <div class="stapel">

    <?php /* ---------------------------------------- Empfohlene Aktionen */ ?>
    <div class="karte">
      <div class="karte__kopf">
        <h2><?= Icon::svg('ai', 17) ?> Empfohlene Aktionen</h2>
        <span class="pille pille--lila"><?= count($empfehlungen) ?></span>
        <div class="fueller"></div>
        <span class="klein gedimmt nicht-mobil">aus deinen Daten berechnet</span>
      </div>
      <div class="karte__koerper karte__koerper--eng">
        <?php if ($empfehlungen === []): ?>
          <?= leerzustand('check', 'Nichts liegt an',
                'Keine offenen Anfragen, keine überfälligen Rechnungen, keine auslaufenden Pakete. '
                . 'Das ist der Zustand, den man anstrebt.', []) ?>
        <?php else: ?>
          <div class="stapel stapel--eng">
            <?php foreach ($empfehlungen as $e): ?>
              <div class="empfehlung">
                <div class="empfehlung__symbol empfehlung__symbol--<?= Util::attr($e['farbe']) ?>">
                  <?= Icon::svg($e['icon'], 17) ?>
                </div>
                <div class="empfehlung__text">
                  <div class="empfehlung__titel"><?= Util::h($e['titel']) ?></div>
                  <div class="empfehlung__grund"><?= Util::h($e['grund']) ?></div>
                </div>
                <div class="empfehlung__aktion">
                  <a class="btn btn--klein" href="<?= Util::attr(App::url($e['url'])) ?>">
                    <?= Util::h($e['knopf']) ?> <?= Icon::svg('chevron-right', 13) ?>
                  </a>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <?php /* -------------------------------------------- Termine heute */ ?>
    <div class="karte">
      <div class="karte__kopf">
        <h2>Heute</h2>
        <div class="fueller"></div>
        <a class="btn btn--klein btn--geist" href="<?= Util::attr(App::url('/app/kalender.php')) ?>">
          Kalender <?= Icon::svg('chevron-right', 13) ?>
        </a>
      </div>
      <?php if ($termineHeute === []): ?>
        <div class="karte__koerper">
          <?= leerzustand('calendar', 'Keine Termine heute',
                'Ein guter Moment, um inaktive Kunden anzuschreiben oder an der Website zu arbeiten.',
                Auth::darf('bookings.write') ? [['Termin anlegen', '/app/buchung.php?id=neu', 'plus']] : []) ?>
        </div>
      <?php else: ?>
        <div class="tabelle-huelle">
          <table class="tabelle">
            <tbody>
            <?php foreach ($termineHeute as $b):
              $kunde = (int) $b['customer_id'] > 0 ? Tenant::find('customers', (int) $b['customer_id']) : null;
              $vorbei = strtotime((string) $b['ende']) < time(); ?>
              <tr<?= $vorbei ? ' style="opacity:.55"' : '' ?>>
                <td style="width:78px" class="mono halbfett">
                  <?= Util::h(Util::uhrzeit((string) $b['start'])) ?>
                </td>
                <td>
                  <a class="haupt" href="<?= Util::attr(App::url('/app/buchung.php?id=' . (int) $b['id'])) ?>">
                    <?= Util::h((string) $b['titel']) ?></a>
                  <div class="klein gedimmt">
                    <?= Util::h((int) $b['teilnehmer'] > 1 ? (int) $b['teilnehmer'] . ' Teilnehmer' : '') ?>
                    <?= (int) $b['teilnehmer'] > 1 ? ' · ' : '' ?>
                    <?= Util::h(Auth::trainerName((int) $b['trainer_id'])) ?>
                  </div>
                </td>
                <td>
                  <?php if ($kunde): ?>
                    <?= person(Customers::name($kunde), [
                          'unter' => (string) $kunde['hcp'] !== '' ? 'HCP ' . Util::hcp((string) $kunde['hcp']) : '',
                          'url' => '/app/kunde.php?id=' . (int) $kunde['id'],
                        ]) ?>
                  <?php endif; ?>
                </td>
                <td class="rechts umbruch-nein">
                  <?= pille(Bookings::statusName((string) $b['status']), Bookings::statusFarbe((string) $b['status'])) ?>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>

    <?php /* ---------------------------------------------- Umsatzverlauf */ ?>
    <?php if (Auth::darf('analytics.view')): ?>
    <div class="karte">
      <div class="karte__kopf">
        <h2>Umsatz der letzten 30 Tage</h2>
        <div class="fueller"></div>
        <a class="btn btn--klein btn--geist" href="<?= Util::attr(App::url('/app/auswertung.php')) ?>">
          Auswertung <?= Icon::svg('chevron-right', 13) ?>
        </a>
      </div>
      <div class="karte__koerper">
        <?= Diagramm::saeulen($verlauf['labels'], $verlauf['werte'], ['format' => 'geld', 'hoehe' => 210]) ?>
      </div>
    </div>
    <?php endif; ?>

    <?php /* ----------------------------------------- Anfragen & Kunden */ ?>
    <div class="raster raster--2">
      <?php if (Auth::darf('leads.view')): ?>
      <div class="karte">
        <div class="karte__kopf"><h3>Neue Anfragen</h3>
          <div class="fueller"></div>
          <a class="klein" href="<?= Util::attr(App::url('/app/leads.php')) ?>">alle</a>
        </div>
        <div class="karte__koerper karte__koerper--eng">
          <?php if ($neueLeads === []): ?>
            <p class="gedimmt klein mitte" style="padding:var(--r4) 0">Keine offenen Anfragen.</p>
          <?php else: ?>
            <div class="stapel stapel--eng">
              <?php foreach ($neueLeads as $l): ?>
                <a class="reihe" href="<?= Util::attr(App::url('/app/lead.php?id=' . (int) $l['id'])) ?>"
                   style="padding:6px;border-radius:8px;color:inherit">
                  <?= person((string) $l['name'], ['unter' => (string) $l['interesse']]) ?>
                  <div class="fueller"></div>
                  <span class="winzig gedimmt-2 umbruch-nein"><?= Util::h(Util::relativ((string) $l['erstellt'])) ?></span>
                </a>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      </div>
      <?php endif; ?>

      <div class="karte">
        <div class="karte__kopf"><h3>Neue Kunden</h3>
          <div class="fueller"></div>
          <a class="klein" href="<?= Util::attr(App::url('/app/kunden.php')) ?>">alle</a>
        </div>
        <div class="karte__koerper karte__koerper--eng">
          <?php if ($neueKunden === []): ?>
            <p class="gedimmt klein mitte" style="padding:var(--r4) 0">In den letzten 30 Tagen keine.</p>
          <?php else: ?>
            <div class="stapel stapel--eng">
              <?php foreach ($neueKunden as $k): ?>
                <a class="reihe" href="<?= Util::attr(App::url('/app/kunde.php?id=' . (int) $k['id'])) ?>"
                   style="padding:6px;border-radius:8px;color:inherit">
                  <?= person(Customers::name($k), [
                        'unter' => trim(((string) $k['hcp'] !== '' ? 'HCP ' . Util::hcp((string) $k['hcp']) . ' · ' : '') . (string) $k['quelle'], ' ·'),
                      ]) ?>
                  <div class="fueller"></div>
                  <span class="winzig gedimmt-2 umbruch-nein"><?= Util::h(Util::relativ((string) $k['erstellt'])) ?></span>
                </a>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>

  <?php /* ============================================ Rechte Spalte === */ ?>
  <div class="stapel">

    <div class="karte">
      <div class="karte__kopf"><h3>Nächste Termine</h3></div>
      <div class="karte__koerper karte__koerper--eng">
        <?php if ($kommende === []): ?>
          <p class="gedimmt klein mitte" style="padding:var(--r3) 0">Nichts geplant.</p>
        <?php else: ?>
          <div class="strahl">
            <?php foreach ($kommende as $b): ?>
              <div class="strahl__punkt strahl__punkt--marke">
                <div class="strahl__zeit">
                  <?= Util::h(Util::datum((string) $b['start'], false)) ?>,
                  <?= Util::h(Util::uhrzeit((string) $b['start'])) ?> Uhr
                </div>
                <a class="strahl__titel" href="<?= Util::attr(App::url('/app/buchung.php?id=' . (int) $b['id'])) ?>">
                  <?= Util::h((string) $b['titel']) ?></a>
                <?php if ((int) $b['customer_id'] > 0): ?>
                  <div class="strahl__text"><?= Util::h(Customers::nameVonId((int) $b['customer_id'])) ?></div>
                <?php endif; ?>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <div class="karte">
      <div class="karte__kopf"><h3>Aufgaben</h3>
        <div class="fueller"></div>
        <button class="btn btn--klein btn--geist" data-modal-auf="modal-aufgabe">
          <?= Icon::svg('plus', 14) ?></button>
      </div>
      <div class="karte__koerper karte__koerper--eng">
        <?php if ($aufgaben === []): ?>
          <p class="gedimmt klein mitte" style="padding:var(--r3) 0">Alles erledigt.</p>
        <?php else: ?>
          <div class="stapel stapel--eng">
            <?php foreach ($aufgaben as $a):
              $ueberfaellig = $a['faellig'] && strtotime((string) $a['faellig']) < time(); ?>
              <form method="post" action="<?= Util::attr(App::url('/app/aufgaben.php')) ?>" class="reihe reihe--eng">
                <?= Auth::csrfFeld() ?>
                <input type="hidden" name="aktion" value="erledigt">
                <input type="hidden" name="id" value="<?= (int) $a['id'] ?>">
                <button type="submit" class="rundknopf" style="width:24px;height:24px"
                        aria-label="Als erledigt markieren">
                  <span style="width:15px;height:15px;border:1.5px solid var(--rand-stark);border-radius:5px;display:block"></span>
                </button>
                <div style="flex:1;min-width:0">
                  <div style="font-size:13px"><?= Util::h((string) $a['titel']) ?></div>
                  <?php if ($a['faellig']): ?>
                    <div class="winzig <?= $ueberfaellig ? '' : 'gedimmt-2' ?>"
                         style="<?= $ueberfaellig ? 'color:var(--gefahr)' : '' ?>">
                      <?= Util::h(Util::relativ((string) $a['faellig'])) ?>
                    </div>
                  <?php endif; ?>
                </div>
              </form>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <?php if (Auth::darf('invoices.view') && $offeneRechnungen !== []): ?>
    <div class="karte">
      <div class="karte__kopf"><h3>Offene Rechnungen</h3>
        <div class="fueller"></div>
        <span class="pille pille--warnung"><?= Util::h(Util::geldKurz(Invoices::offenerBetrag())) ?></span>
      </div>
      <div class="karte__koerper karte__koerper--eng">
        <div class="stapel stapel--eng">
          <?php foreach ($offeneRechnungen as $r): ?>
            <a class="reihe" href="<?= Util::attr(App::url('/app/rechnung.php?id=' . (int) $r['id'])) ?>"
               style="padding:5px 6px;border-radius:8px;color:inherit">
              <div style="flex:1;min-width:0">
                <div style="font-size:13px;font-weight:540">
                  <?= Util::h(Customers::nameVonId((int) $r['customer_id'])) ?></div>
                <div class="winzig gedimmt-2"><?= Util::h((string) $r['nummer']) ?> ·
                  fällig <?= Util::h(Util::datum((string) $r['faellig'])) ?></div>
              </div>
              <span class="tabnum halbfett klein"><?= Util::h(Util::geldKurz((int) $r['summe_cent'])) ?></span>
              <?php if ((string) $r['status'] === 'ueberfaellig'): ?>
                <span class="pille pille--gefahr">überfällig</span>
              <?php endif; ?>
            </a>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
    <?php endif; ?>

    <div class="karte">
      <div class="karte__kopf"><h3>Auf einen Blick</h3></div>
      <div class="karte__koerper karte__koerper--eng">
        <div class="stapel stapel--eng">
          <?php
          $zahlen = [
            ['Aktive Trainingspakete', Util::zahl($aktivePakete),
             $offeneEinheiten . ' offene Einheiten', '/app/pakete.php', 'ticket'],
            ['Kunden ohne Termin (60 Tage)', Util::zahl($inaktiv),
             $inaktiv > 0 ? 'Anruf lohnt sich' : 'alles in Bewegung', '/app/kunden.php?filter=inaktiv', 'customers'],
            ['Website-Besucher (30 Tage)', Util::zahl($besucher30),
             Util::prozent($conversion, 1) . ' werden zur Anfrage', '/app/auswertung.php#website', 'website'],
          ];
          foreach ($zahlen as [$label, $wert, $unterzeile, $url, $icon]): ?>
            <a class="reihe" href="<?= Util::attr(App::url($url)) ?>" style="padding:6px;border-radius:8px;color:inherit">
              <span class="kachel-link__symbol" style="width:30px;height:30px;border-radius:9px">
                <?= Icon::svg($icon, 15) ?></span>
              <div style="flex:1;min-width:0">
                <div class="klein halbfett"><?= Util::h($label) ?></div>
                <div class="winzig gedimmt-2"><?= Util::h($unterzeile) ?></div>
              </div>
              <span class="halbfett tabnum"><?= Util::h($wert) ?></span>
            </a>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

    <?php if (Auth::darf('ai.use') && Tenant::modul('ai')): ?>
    <div class="ki-block">
      <div class="ki-block__kopf"><?= Icon::svg('ai', 15) ?> Frag deine Zahlen</div>
      <form method="get" action="<?= Util::attr(App::url('/app/ki.php')) ?>">
        <div class="feld">
          <input class="eingabe" name="frage" placeholder="Wen sollte ich heute anrufen?"
                 aria-label="Frage an den Assistenten">
        </div>
        <div class="reihe reihe--eng" style="flex-wrap:wrap">
          <?php foreach (['Umsatz diesen Monat', 'Wen sollte ich heute anrufen?',
                          'Wie voll ist meine nächste Woche?'] as $bsp): ?>
            <a class="pille pille--offen" href="<?= Util::attr(App::url('/app/ki.php?frage=' . rawurlencode($bsp))) ?>">
              <?= Util::h($bsp) ?></a>
          <?php endforeach; ?>
        </div>
      </form>
    </div>
    <?php endif; ?>
  </div>
</div>

<dialog class="modal modal--schmal" id="modal-aufgabe">
  <form method="post" action="<?= Util::attr(App::url('/app/aufgaben.php')) ?>">
    <?= Auth::csrfFeld() ?>
    <input type="hidden" name="aktion" value="neu">
    <div class="modal__kopf"><h2>Neue Aufgabe</h2>
      <button type="button" class="rundknopf" data-modal-zu aria-label="Schließen"><?= Icon::svg('x', 17) ?></button>
    </div>
    <div class="modal__koerper">
      <div class="feld">
        <label class="feld__label" for="a-titel">Was ist zu tun?</label>
        <input class="eingabe" id="a-titel" name="titel" required autofocus placeholder="Angebot für Firmenevent schicken">
      </div>
      <div class="feld">
        <label class="feld__label" for="a-faellig">Bis wann</label>
        <input class="eingabe" id="a-faellig" type="date" name="faellig"
               value="<?= Util::attr(date('Y-m-d', strtotime('+1 day'))) ?>">
      </div>
    </div>
    <div class="modal__fuss">
      <div class="fueller"></div>
      <button type="button" class="btn" data-modal-zu>Abbrechen</button>
      <button type="submit" class="btn btn--primaer">Anlegen</button>
    </div>
  </form>
</dialog>

<?php require __DIR__ . '/partials/fuss.php'; ?>
