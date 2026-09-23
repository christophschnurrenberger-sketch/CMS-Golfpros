<?php
/**
 * Auswertung – wie sich die Plattform entwickelt und was genutzt wird.
 *
 * Alles aus echten Zeilen; Demo-Instanzen zählen nicht mit. Wo eine
 * Nutzung nicht eindeutig messbar ist, bleibt die Spalte leer, statt
 * einen Wert zu raten.
 */
require __DIR__ . '/partials/start.php';
Betreiber::fordern('auswertung.view');

$wachstum = Plattformzahlen::wachstum(12);
$termine = Plattformzahlen::termineJeMonat(12);
$verteilung = Plattformzahlen::paketVerteilung();
$gesundheit = Plattformzahlen::gesundheit();
$module = Plattformzahlen::modulNutzung();
$zahlen = Plattformzahlen::uebersicht();
$echte = max(1, $zahlen['gesamt']);

$titel = 'Auswertung';
$unter = 'Wachstum, Pakete und Nutzung über alle echten Instanzen'
       . ($zahlen['demo'] > 0 ? ' · ' . $zahlen['demo'] . ' Demo-Instanz' . ($zahlen['demo'] === 1 ? '' : 'en') . ' nicht mitgezählt' : '');
$bereich = 'auswertung';
$brotkrumen = [['Übersicht', '/master/'], ['Auswertung', null]];
require __DIR__ . '/partials/kopf.php';
?>

<div class="raster raster--2 mb-5">
  <?= karteAuf('Neue Instanzen je Monat') ?>
    <div class="karte__koerper">
      <?= Diagramm::saeulen($wachstum['labels'], $wachstum['neu'], ['hoehe' => 200, 'ganz' => true]) ?>
      <p class="klein gedimmt mt-3 mb-0">Gezählt nach Anlagedatum. Gelöschte Instanzen fehlen, weil es ihre Zeilen nicht mehr gibt.</p>
    </div>
  </div>
  <?= karteAuf('Termine je Monat, alle Instanzen') ?>
    <div class="karte__koerper">
      <?= Diagramm::saeulen($termine['labels'], $termine['werte'], ['hoehe' => 200, 'ganz' => true]) ?>
      <p class="klein gedimmt mt-3 mb-0">Nach Termindatum, ohne abgesagte. Sagt, wie viel über TeePilot organisiert wird – nicht, was es kostet.</p>
    </div>
  </div>
</div>

<div class="raster raster--2 mb-5">
  <?= karteAuf('Pakete') ?>
    <div class="karte__koerper">
      <?php if ($verteilung === []): ?><p class="gedimmt mb-0">Noch keine Instanzen.</p><?php else: ?>
        <div class="rangliste">
          <?php $s = max(1, array_sum($verteilung)); foreach ($verteilung as $plan => $n): ?>
            <a class="rang rang--link" href="<?= Util::attr(App::url('/master/instanzen.php?paket=' . rawurlencode($plan))) ?>">
              <span class="rang__name"><?= Util::h(Pakete::name($plan)) ?></span>
              <span class="rang__wert"><?= $n ?> <span class="gedimmt klein"><?= Util::prozent($n / $s * 100) ?></span></span>
              <span class="rang__spur"><span class="rang__fuellung" data-breite="<?= (int) round($n / $s * 100) ?>%"></span></span></a>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>
  <?= karteAuf('Nutzung der aktiven Instanzen') ?>
    <div class="karte__koerper">
      <div class="rangliste">
        <?php $s = max(1, array_sum($gesundheit)); foreach ($gesundheit as $key => $n): ?>
          <a class="rang rang--link" href="<?= Util::attr(App::url('/master/instanzen.php?nutzung=' . $key)) ?>">
            <span class="rang__name"><?= Util::h(Instanzen::GESUNDHEIT[$key][0]) ?></span>
            <span class="rang__wert"><?= $n ?></span>
            <span class="rang__spur"><span class="rang__fuellung rang__fuellung--<?= Instanzen::GESUNDHEIT[$key][1] ?>" data-breite="<?= (int) round($n / $s * 100) ?>%"></span></span></a>
        <?php endforeach; ?>
      </div>
      <p class="klein gedimmt mt-3 mb-0">Schwellen: aktiv bis <?= Plattform::zahl('aktiv_tage') ?> Tage, inaktiv ab <?= Plattform::zahl('inaktiv_tage') ?> Tagen –
        änderbar unter <a href="<?= Util::attr(App::url('/master/einstellungen.php')) ?>">Einstellungen</a>.</p>
    </div>
  </div>
</div>

<?= karteAuf('Module: eingeschaltet und benutzt') ?>
  <div class="tabelle-huelle">
    <table class="tabelle tabelle--eng">
      <thead><tr><th>Modul</th><th class="zahl">Eingeschaltet</th><th>Anteil</th><th class="zahl">Benutzt, letzte 30 Tage</th></tr></thead>
      <tbody>
        <?php foreach ($module as $key => $m): ?>
          <tr>
            <td><?= Util::h(Module::name($key)) ?><?= Module::istKern($key) ? ' <span class="winzig gedimmt-2">Kern</span>' : '' ?></td>
            <td class="zahl"><?= $m['an'] ?></td>
            <td style="min-width:140px"><?= balken((int) round($m['an'] / $echte * 100)) ?></td>
            <td class="zahl"><?= $m['genutzt'] === null ? '<span class="gedimmt" title="Für dieses Modul gibt es keine eindeutige Spur.">—</span>' : (int) $m['genutzt'] ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="karte__fuss klein gedimmt">„Benutzt" zählt Instanzen mit neuen Datensätzen in 30 Tagen: Termine (Buchungen), Kunden, Rechnungen, Leads,
    versendete Newsletter; bei der Website veröffentlichte Seiten. Für die übrigen Module gibt es keine eindeutige Spur – dort steht nichts.</div>
</div>

<?php require __DIR__ . '/partials/fuss.php';
