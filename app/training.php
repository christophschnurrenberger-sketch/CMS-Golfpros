<?php
/**
 * Trainingsbereich: Pläne, Übungsbibliothek und Leistungsdaten.
 */
require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/partials/helfer.php';

Auth::fordern('modul.training');

$plaene = DB::all(
    'SELECT p.*, (SELECT COUNT(*) FROM plan_assignments a WHERE a.plan_id = p.id) AS zuweisungen,
            (SELECT COUNT(*) FROM plan_items i WHERE i.plan_id = p.id) AS uebungen
     FROM training_plans p WHERE p.workspace_id = :w ORDER BY p.vorlage, p.id DESC',
    ['w' => Tenant::id()]
);
$vorlagen = array_values(array_filter($plaene, static fn($p) => (int) $p['vorlage'] === 1));
$zugewiesen = array_values(array_filter($plaene, static fn($p) => (int) $p['vorlage'] === 0));
$uebungen = Training::uebungen();

$titel = 'Training';
$unter = count($zugewiesen) . ' zugewiesene Pläne · ' . count($vorlagen) . ' Vorlagen · '
       . count($uebungen) . ' Übungen';
$aktionen = Auth::darf('training.write')
    ? '<a class="btn" href="' . App::url('/app/uebungen.php') . '">' . Icon::svg('dumbbell', 15) . ' Übungen</a>'
      . '<a class="btn" href="' . App::url('/app/trainingsplan.php?id=neu&ki=1') . '">' . Icon::svg('ai', 15) . ' Mit KI</a>'
      . '<a class="btn btn--primaer" href="' . App::url('/app/trainingsplan.php?id=neu') . '">'
      . Icon::svg('plus', 15) . ' Plan erstellen</a>' : '';
require __DIR__ . '/partials/kopf.php';
?>

<?php if ($plaene === []): ?>
  <div class="karte"><div class="karte__koerper">
    <?= leerzustand('training', 'Noch kein Trainingsplan',
          'Ein Plan gibt dem Kunden zwischen den Stunden etwas zu tun. Das ist der größte '
          . 'Unterschied zwischen langsamem und schnellem Fortschritt – und der häufigste Grund, '
          . 'warum Kunden bleiben.',
          Auth::darf('training.write')
            ? [['Plan erstellen', '/app/trainingsplan.php?id=neu', 'plus'],
               ['Mit KI erstellen', '/app/trainingsplan.php?id=neu&ki=1', 'ai']]
            : []) ?>
  </div></div>
<?php else: ?>

<div class="raster raster--haupt-neben">
  <div class="stapel">
    <div class="karte">
      <div class="karte__kopf"><h2>Zugewiesene Pläne</h2>
        <span class="pille"><?= count($zugewiesen) ?></span></div>
      <?php if ($zugewiesen === []): ?>
        <div class="karte__koerper"><p class="klein gedimmt mitte">
          Noch kein Plan einem Kunden zugewiesen.</p></div>
      <?php else: ?>
        <div class="tabelle-huelle">
          <table class="tabelle tabelle--klickbar">
            <thead><tr><th>Plan</th><th>Kunde</th><th class="nicht-mobil">Umfang</th>
              <th>Fortschritt</th><th>Status</th></tr></thead>
            <tbody>
            <?php foreach ($zugewiesen as $p):
              $url = App::url('/app/trainingsplan.php?id=' . (int) $p['id']);
              $fortschritt = Training::fortschritt((int) $p['id'], (int) $p['customer_id']); ?>
              <tr onclick="location.href='<?= Util::attr($url) ?>'">
                <td>
                  <span class="haupt"><?= Util::h((string) $p['name']) ?></span>
                  <?php if ((string) $p['quelle'] === 'ai'): ?>
                    <span class="pille pille--ki"><?= Icon::svg('ai', 11) ?> KI</span>
                  <?php endif; ?>
                  <div class="winzig gedimmt-2"><?= Util::h(Util::kuerzen((string) $p['ziel'], 60)) ?></div>
                </td>
                <td><?= (int) $p['customer_id'] > 0
                      ? Util::h(Customers::nameVonId((int) $p['customer_id']))
                      : '<span class="gedimmt-2">—</span>' ?></td>
                <td class="nicht-mobil gedimmt"><?= (int) $p['wochen'] ?> Wochen ·
                  <?= (int) $p['uebungen'] ?> Übungen</td>
                <td style="min-width:130px"><?= balken($fortschritt) ?>
                  <div class="winzig gedimmt-2 mt-2"><?= $fortschritt ?> %</div></td>
                <td><?= pille((string) $p['status'], (string) $p['status'] === 'aktiv' ? 'erfolg' : 'offen') ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>

    <?php if ($vorlagen !== []): ?>
    <div class="karte">
      <div class="karte__kopf"><h2>Vorlagen</h2>
        <div class="fueller"></div>
        <span class="klein gedimmt">für mehrere Kunden wiederverwendbar</span></div>
      <div class="karte__koerper karte__koerper--eng">
        <div class="stapel stapel--eng">
          <?php foreach ($vorlagen as $p): ?>
            <a class="kachel-link" href="<?= Util::attr(App::url('/app/trainingsplan.php?id=' . (int) $p['id'])) ?>">
              <span class="kachel-link__symbol"><?= Icon::svg('layers', 17) ?></span>
              <div style="flex:1;min-width:0">
                <div class="kachel-link__titel"><?= Util::h((string) $p['name']) ?></div>
                <div class="kachel-link__text"><?= (int) $p['wochen'] ?> Wochen ·
                  <?= (int) $p['uebungen'] ?> Übungen · <?= (int) $p['zuweisungen'] ?>× zugewiesen</div>
              </div>
              <?= Icon::svg('chevron-right', 16) ?>
            </a>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
    <?php endif; ?>
  </div>

  <div class="stapel">
    <div class="karte">
      <div class="karte__kopf"><h3>Übungsbibliothek</h3>
        <div class="fueller"></div>
        <a class="klein" href="<?= Util::attr(App::url('/app/uebungen.php')) ?>">alle</a></div>
      <div class="karte__koerper karte__koerper--eng">
        <?php
        $nachKategorie = [];
        foreach ($uebungen as $u) {
            $nachKategorie[(string) $u['kategorie']][] = $u;
        }
        if ($nachKategorie === []): ?>
          <p class="klein gedimmt mitte" style="padding:var(--r3) 0">Noch keine Übungen.</p>
        <?php else: ?>
          <div class="reihe reihe--eng reihe--umbruch">
            <?php foreach ($nachKategorie as $kat => $liste): ?>
              <a class="pille pille--offen" href="<?= Util::attr(App::url('/app/uebungen.php?kategorie=' . $kat)) ?>">
                <?= Util::h(Training::KATEGORIEN[$kat] ?? $kat) ?>
                <span class="gedimmt-2"><?= count($liste) ?></span></a>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <div class="karte">
      <div class="karte__kopf"><h3>Leistungsdaten</h3></div>
      <div class="karte__koerper karte__koerper--eng">
        <?php
        $mitDaten = DB::all(
            'SELECT c.id, c.vorname, c.nachname, COUNT(p.id) AS runden, MAX(p.datum) AS letzte
             FROM performance_entries p JOIN customers c ON c.id = p.customer_id
             WHERE p.workspace_id = :w GROUP BY c.id ORDER BY letzte DESC LIMIT 8',
            ['w' => Tenant::id()]
        );
        if ($mitDaten === []): ?>
          <p class="klein gedimmt mitte" style="padding:var(--r3) 0">
            Noch keine Runden erfasst. Score, Fairways und Putts zeigen nach drei Runden,
            wo die Schläge liegen bleiben.</p>
        <?php else: ?>
          <div class="stapel stapel--eng">
            <?php foreach ($mitDaten as $m): ?>
              <a class="reihe" href="<?= Util::attr(App::url('/app/kunde.php?id=' . (int) $m['id'] . '#leistung')) ?>"
                 style="padding:6px;border-radius:8px;color:inherit">
                <?= person(trim($m['vorname'] . ' ' . $m['nachname']),
                      ['unter' => (int) $m['runden'] . ' Runden']) ?>
                <div class="fueller"></div>
                <span class="winzig gedimmt-2"><?= Util::h(Util::datum((string) $m['letzte'], false)) ?></span>
              </a>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<?php require __DIR__ . '/partials/fuss.php'; ?>
