<?php
/**
 * TeePilot Übersicht – das Dashboard der Betreiberzentrale.
 *
 * Jede Kachel ist eine Abfrage auf echte Daten (Plattformzahlen). Was
 * nicht belegt ist, steht nicht da: Es gibt keinen Umsatz, solange keine
 * Abrechnung angeschlossen ist – nur den Vertragswert zu Listenpreisen,
 * und der sagt ausdrücklich, was er ist.
 */
require __DIR__ . '/partials/start.php';

$zahlen = Plattformzahlen::uebersicht();
$wachstum = Plattformzahlen::wachstum(12);
$verteilung = Plattformzahlen::paketVerteilung();
$achtung = Plattformzahlen::aufmerksamkeit();
$gesundheit = Plattformzahlen::gesundheit();
$verlauf = Betreiberlog::liste([], 8);
$stand = date('H:i:s');

$titel = 'TeePilot Übersicht';
$unter = 'Behalte Instanzen, Pakete und Produktnutzung zentral im Blick.';
$bereich = 'uebersicht';
$aktionen = '<span class="klein gedimmt" aria-live="polite">Zuletzt aktualisiert: <span class="mono">' . $stand . '</span></span>'
          . '<a class="btn" href="' . Util::attr(App::url('/master/')) . '">' . Icon::svg('refresh', 15) . ' Aktualisieren</a>';
require __DIR__ . '/partials/kopf.php';

$vergleich = $zahlen['termine_vormonat'] > 0
    ? Util::wachstum((float) $zahlen['termine_monat'], (float) $zahlen['termine_vormonat']) : null;
$pakete = Pakete::alle();
$summeVerteilung = max(1, array_sum($verteilung));
?>

<?php if ($zahlen['demo'] > 0): ?>
  <p class="klein gedimmt mb-4"><?= Icon::svg('info', 14) ?>
    <?= $zahlen['demo'] === 1 ? 'Eine Demo-Instanz ist' : $zahlen['demo'] . ' Demo-Instanzen sind' ?>
    in keiner Zahl dieser Seite enthalten – ihre Daten sind erfunden.</p>
<?php endif; ?>

<div class="raster raster--4 mb-5">
  <?= kennzahl('Instanzen', Util::zahl($zahlen['gesamt']), ['icon' => 'building', 'url' => '/master/instanzen.php',
       'fuss' => $zahlen['neu_30'] . ' neu in 30 Tagen · ohne Archiv']) ?>
  <?= kennzahl('Aktiv', Util::zahl($zahlen['status']['aktiv']), ['icon' => 'check', 'url' => '/master/instanzen.php?status=aktiv',
       'fuss' => Util::zahl($zahlen['status']['test']) . ' in Testphase']) ?>
  <?= kennzahl('Pausiert / gesperrt', Util::zahl($zahlen['status']['pausiert'] + $zahlen['status']['gesperrt']),
       ['icon' => 'pause', 'url' => '/master/instanzen.php?status=pausiert',
        'fuss' => $zahlen['status']['pausiert'] . ' pausiert · ' . $zahlen['status']['gesperrt'] . ' gesperrt']) ?>
  <?= kennzahl('Benutzer', Util::zahl($zahlen['benutzer']), ['icon' => 'customers', 'url' => '/master/benutzer.php',
       'fuss' => 'aktive Zugänge in Instanzen']) ?>
  <?= kennzahl('Termine diesen Monat', Util::zahl($zahlen['termine_monat']), ['icon' => 'calendar', 'delta' => $vergleich,
       'fuss' => 'ohne abgesagte · Vormonat ' . Util::zahl($zahlen['termine_vormonat'])]) ?>
  <?= kennzahl('Pakete', Util::zahl($zahlen['pakete']), ['icon' => 'layers', 'url' => '/master/pakete.php',
       'fuss' => 'aktiv angeboten']) ?>
  <div class="kennzahl">
    <div class="kennzahl__kopf"><span class="kennzahl__label">Subscription-Wert</span>
      <span class="kennzahl__symbol"><?= Icon::svg('euro', 16) ?></span></div>
    <div class="kennzahl__wert"><?= $zahlen['vertraege'] > 0 ? Util::h(Util::geld($zahlen['vertragswert'])) : '—' ?>
      <?php if ($zahlen['vertraege'] > 0): ?><small>/ Monat</small><?php endif; ?></div>
    <div class="kennzahl__fuss"><span class="gedimmt">Listenpreise aus <?= $zahlen['vertraege'] ?>
      <?= $zahlen['vertraege'] === 1 ? 'Vertrag' : 'Verträgen' ?> · <?= $zahlen['ohne_vertrag'] ?> ohne Vertragsdaten</span></div>
    <div class="kennzahl__fuss"><span class="pille pille--offen">Abrechnung noch nicht verbunden</span></div>
  </div>
  <?= kennzahl('Gekündigt', Util::zahl($zahlen['gekuendigt']), ['icon' => 'logout',
       'fuss' => 'laufen noch bis Vertragsende']) ?>
</div>

<div class="raster raster--haupt-neben mb-5">
  <?= karteAuf('Wachstum der Instanzen', '<span class="klein gedimmt">letzte 12 Monate</span>') ?>
    <div class="karte__koerper">
      <?php if (array_sum($wachstum['neu']) === 0 && end($wachstum['bestand']) === 0): ?>
        <?= leerzustand('building', 'Noch keine Instanzen', 'Sobald Instanzen angelegt sind, zeigt sich hier, wie der Bestand wächst.',
              [['Erste Instanz anlegen', '/master/instanz-neu.php', 'plus']]) ?>
      <?php else: ?>
        <?= Diagramm::saeulen($wachstum['labels'], $wachstum['bestand'], ['hoehe' => 210, 'ganz' => true]) ?>
        <p class="klein gedimmt mt-3 mb-0">Bestand am Monatsende (heute noch vorhandene Instanzen, ohne Demo).
          Neu angelegt in 12 Monaten: <b><?= array_sum($wachstum['neu']) ?></b>.</p>
      <?php endif; ?>
    </div>
  </div>

  <?= karteAuf('Paketverteilung') ?>
    <div class="karte__koerper">
      <?php if ($verteilung === []): ?>
        <p class="gedimmt">Noch keine Instanzen.</p>
      <?php else: ?>
        <div class="rangliste">
          <?php foreach ($verteilung as $plan => $n): ?>
            <a class="rang rang--link" href="<?= Util::attr(App::url('/master/instanzen.php?paket=' . rawurlencode($plan))) ?>">
              <span class="rang__name"><?= Util::h(Pakete::name($plan)) ?></span>
              <span class="rang__wert"><?= $n ?> <span class="gedimmt klein"><?= Util::prozent($n / $summeVerteilung * 100) ?></span></span>
              <span class="rang__spur"><span class="rang__fuellung" data-breite="<?= (int) round($n / $summeVerteilung * 100) ?>%"></span></span>
            </a>
          <?php endforeach; ?>
        </div>
        <p class="klein gedimmt mt-3 mb-0">Klick auf ein Paket filtert die Instanzliste.</p>
      <?php endif; ?>
    </div>
  </div>
</div>

<div class="raster raster--haupt-neben mb-5">
  <?= karteAuf('Aufmerksamkeit erforderlich') ?>
    <div class="karte__koerper">
      <?php $nichts = $achtung['test_endet'] === [] && $achtung['inaktiv'] === [] && $achtung['kuendigung'] === [] && $achtung['neu'] === []; ?>
      <?php if ($nichts): ?>
        <p class="gedimmt mb-0"><?= Icon::svg('check', 15) ?> Nichts offen: keine auslaufende Testphase, keine inaktive Instanz,
          keine anstehende Kündigung.</p>
      <?php endif; ?>

      <?php if ($achtung['test_endet'] !== []): ?>
        <h3 class="achtung__titel">Testphase endet in den nächsten 7 Tagen</h3>
        <ul class="achtung">
          <?php foreach ($achtung['test_endet'] as $w): $tage = Util::tageBis((string) $w['test_bis']); ?>
            <li><a href="<?= Util::attr(App::url('/master/instanz.php?id=' . (int) $w['id'] . '&reiter=paket')) ?>"><?= Util::h((string) $w['name']) ?></a>
              <span class="pille pille--<?= $tage < 0 ? 'gefahr' : 'info' ?>"><?= $tage < 0 ? 'abgelaufen seit ' . Util::datum((string) $w['test_bis'])
                : ($tage === 0 ? 'endet heute' : 'noch ' . $tage . ' ' . ($tage === 1 ? 'Tag' : 'Tage')) ?></span></li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>

      <?php if ($achtung['inaktiv'] !== []): ?>
        <h3 class="achtung__titel">Inaktiv seit mehr als <?= Plattform::zahl('inaktiv_tage') ?> Tagen oder nie genutzt</h3>
        <ul class="achtung">
          <?php foreach ($achtung['inaktiv'] as $w): ?>
            <li><a href="<?= Util::attr(App::url('/master/instanz.php?id=' . (int) $w['id'])) ?>"><?= Util::h((string) $w['name']) ?></a>
              <span class="klein gedimmt"><?= $w['letzte_aktivitaet'] ? 'zuletzt ' . wann((string) $w['letzte_aktivitaet'])
                : 'noch nie genutzt · angelegt ' . wann((string) $w['erstellt']) ?></span></li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>

      <?php if ($achtung['kuendigung'] !== []): ?>
        <h3 class="achtung__titel">Kündigung wird in den nächsten 30 Tagen wirksam</h3>
        <ul class="achtung">
          <?php foreach ($achtung['kuendigung'] as $w): ?>
            <li><a href="<?= Util::attr(App::url('/master/instanz.php?id=' . (int) $w['id'] . '&reiter=paket')) ?>"><?= Util::h((string) $w['name']) ?></a>
              <span class="klein gedimmt">zum <?= Util::h(Util::datum((string) $w['ende'])) ?></span></li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>

      <?php if ($achtung['neu'] !== []): ?>
        <h3 class="achtung__titel">Neu in den letzten 7 Tagen</h3>
        <ul class="achtung">
          <?php foreach ($achtung['neu'] as $w): ?>
            <li><a href="<?= Util::attr(App::url('/master/instanz.php?id=' . (int) $w['id'])) ?>"><?= Util::h((string) $w['name']) ?></a>
              <?= Instanzen::statusPille((string) $w['status']) ?>
              <span class="klein gedimmt"><?= $w['letzte_aktivitaet'] ? 'schon genutzt' : 'noch nicht angemeldet' ?></span></li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </div>
  </div>

  <div class="stapel">
    <?= karteAuf('Nutzung', '<a class="klein" href="' . Util::attr(App::url('/master/auswertung.php')) . '">Auswertung</a>') ?>
      <div class="karte__koerper">
        <div class="rangliste">
          <?php $summeG = max(1, array_sum($gesundheit));
          foreach ($gesundheit as $key => $n): ?>
            <a class="rang rang--link" href="<?= Util::attr(App::url('/master/instanzen.php?nutzung=' . $key)) ?>">
              <span class="rang__name"><?= Util::h(Instanzen::GESUNDHEIT[$key][0]) ?></span>
              <span class="rang__wert"><?= $n ?></span>
              <span class="rang__spur"><span class="rang__fuellung rang__fuellung--<?= Util::attr(Instanzen::GESUNDHEIT[$key][1]) ?>"
                    data-breite="<?= (int) round($n / $summeG * 100) ?>%"></span></span>
            </a>
          <?php endforeach; ?>
        </div>
        <p class="klein gedimmt mt-3 mb-0">Aktive Instanzen und Testphasen. Genutzt heißt: Anmeldung oder Änderung durch das eigene
          Team – aktiv bis <?= Plattform::zahl('aktiv_tage') ?> Tage, inaktiv ab <?= Plattform::zahl('inaktiv_tage') ?> Tagen.</p>
      </div>
    </div>

    <?= karteAuf('Schnellzugriff') ?>
      <div class="karte__koerper karte__koerper--eng schnellzugriff">
        <a class="btn btn--primaer" href="<?= Util::attr(App::url('/master/instanz-neu.php')) ?>"><?= Icon::svg('plus', 15) ?> Neue Instanz</a>
        <a class="btn" href="<?= Util::attr(App::url('/master/instanzen.php')) ?>"><?= Icon::svg('building', 15) ?> Instanzen</a>
        <a class="btn" href="<?= Util::attr(App::url('/master/pakete.php')) ?>"><?= Icon::svg('layers', 15) ?> Pakete</a>
        <a class="btn" href="<?= Util::attr(App::url('/master/protokoll.php')) ?>"><?= Icon::svg('shield', 15) ?> Audit-Log</a>
        <a class="btn" href="<?= Util::attr(App::url('/master/system.php')) ?>"><?= Icon::svg('monitor', 15) ?> System</a>
      </div>
    </div>
  </div>
</div>

<?= karteAuf('Letzte Aktivität in der Betreiberzentrale', '<a class="klein" href="' . Util::attr(App::url('/master/protokoll.php')) . '">Alles ansehen</a>') ?>
  <?php if ($verlauf === []): ?>
    <div class="karte__koerper"><p class="gedimmt mb-0">Noch keine Einträge.</p></div>
  <?php else: ?>
    <div class="tabelle-huelle">
      <table class="tabelle tabelle--eng">
        <thead><tr><th>Wann</th><th>Wer</th><th>Was</th><th>Instanz</th></tr></thead>
        <tbody>
          <?php foreach ($verlauf as $e): ?>
            <tr>
              <td class="umbruch-nein"><?= wann((string) $e['zeit']) ?></td>
              <td><?= Util::h((string) $e['akteur'] ?: '—') ?></td>
              <td><a href="<?= Util::attr(App::url('/master/protokoll.php?id=' . (int) $e['id'])) ?>"><?= Util::h(Betreiberlog::text((string) $e['aktion'])) ?></a>
                <?php if ((string) $e['ergebnis'] !== 'ok'): ?><span class="pille pille--gefahr"><?= Util::h(Betreiberlog::ERGEBNIS[$e['ergebnis']] ?? '') ?></span><?php endif; ?></td>
              <td><?= (int) $e['instanz_id'] > 0
                  ? '<a href="' . Util::attr(App::url('/master/instanz.php?id=' . (int) $e['instanz_id'])) . '">#' . (int) $e['instanz_id'] . '</a>'
                  : '<span class="gedimmt">—</span>' ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/partials/fuss.php';
