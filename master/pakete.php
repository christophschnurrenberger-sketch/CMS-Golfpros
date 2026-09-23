<?php
/**
 * Pakete – was eine Instanz einschalten darf und was es kostet.
 *
 * Die Module eines Pakets sind die einzigen, die es gibt; erfunden wird
 * hier keine Funktion. Grenzen stehen nur da, wo das Produkt sie
 * durchsetzt – heute die Größe des Teams.
 */
require __DIR__ . '/partials/start.php';

$pakete = Pakete::alle();
$titel = 'Pakete';
$unter = count(Pakete::aktive()) . ' aktiv angeboten · Änderungen gelten sofort für jede Instanz auf dem Paket';
$bereich = 'pakete';
$brotkrumen = [['Übersicht', '/master/'], ['Pakete', null]];
$aktionen = '<a class="btn btn--primaer" href="' . Util::attr(App::url('/master/paket.php')) . '">' . Icon::svg('plus', 15) . ' Neues Paket</a>';
require __DIR__ . '/partials/kopf.php';
$standard = (string) Plattform::einstellung('standard_paket');
?>

<div class="karte">
  <div class="tabelle-huelle">
    <table class="tabelle">
      <thead><tr><th>Paket</th><th class="zahl">Monat</th><th class="zahl">Jahr</th><th class="zahl">Module</th>
        <th class="zahl">Team</th><th class="zahl">Instanzen</th><th>Status</th><th class="aktionen"><span class="nur-lesbar">Aktionen</span></th></tr></thead>
      <tbody>
        <?php foreach ($pakete as $k => $p): $n = Pakete::inBenutzung($k); ?>
          <tr>
            <td class="tabelle__haupt"><a href="<?= Util::attr(App::url('/master/paket.php?id=' . (int) $p['id'])) ?>"><?= Util::h((string) $p['name']) ?></a>
              <?= $k === $standard ? '<span class="pille pille--offen">Standard</span>' : '' ?>
              <div class="klein gedimmt"><?= Util::h(Util::kuerzen((string) $p['beschreibung'], 90)) ?></div></td>
            <td class="zahl"><?= (int) $p['preis_monat_cent'] > 0 ? Util::h(Util::geld((int) $p['preis_monat_cent'])) : '<span class="gedimmt">—</span>' ?></td>
            <td class="zahl"><?= (int) $p['preis_jahr_cent'] > 0 ? Util::h(Util::geld((int) $p['preis_jahr_cent'])) : '<span class="gedimmt" title="Kein Jahrespreis festgelegt">—</span>' ?></td>
            <td class="zahl"><?= count($p['module']) ?> <span class="gedimmt">+ Kern</span></td>
            <td class="zahl"><?= (int) $p['team_grenze'] === 0 ? 'unbegrenzt' : (int) $p['team_grenze'] ?></td>
            <td class="zahl"><a href="<?= Util::attr(App::url('/master/instanzen.php?status=alle&paket=' . rawurlencode($k))) ?>"><?= $n ?></a></td>
            <td><?= (int) $p['aktiv'] === 1 ? pille('Angeboten', 'erfolg') : pille('Nicht mehr angeboten', 'offen') ?></td>
            <td class="aktionen"><a class="btn btn--klein" href="<?= Util::attr(App::url('/master/paket.php?id=' . (int) $p['id'])) ?>">Bearbeiten</a></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<p class="klein gedimmt mt-4">Ein Paket, das Instanzen trägt, wird nicht gelöscht, sondern nicht mehr angeboten: Bestehende Instanzen behalten es,
  neue bekommen es nicht mehr. Ob eine Instanz ein Modul benutzen darf, entscheidet allein das Paket – geprüft auf dem Server bei jedem Aufruf.</p>

<?php require __DIR__ . '/partials/fuss.php';
