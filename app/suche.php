<?php
/**
 * Globale Suche – als Seite und als Datenquelle für die Befehlspalette.
 *
 * `?format=json` liefert dieselben Treffer für die Palette. Dass beides
 * aus einer Quelle kommt, ist Absicht: Was die Palette findet, findet die
 * Seite auch, und umgekehrt.
 */
require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/partials/helfer.php';

Auth::fordern('modul.dashboard');

$begriff = App::get('q');
$treffer = $begriff !== '' ? Suche::alles($begriff, App::get('format') === 'json' ? 12 : 60) : [];

if (App::get('format') === 'json') {
    App::json(['treffer' => array_map(static fn($t) => [
        'titel'  => $t['titel'],
        'unter'  => $t['unter'],
        'url'    => $t['url'],
        'gruppe' => $t['gruppe'],
        'icon'   => $t['icon'],
    ], $treffer)]);
}

$titel = $begriff !== '' ? 'Suche: ' . $begriff : 'Suche';
$unter = $begriff !== '' ? count($treffer) . ' Treffer im ganzen System' : '';
require __DIR__ . '/partials/kopf.php';
?>

<div class="karte" style="max-width:820px">
  <div class="karte__koerper">
    <form method="get" action="<?= Util::attr(App::url('/app/suche.php')) ?>">
      <div class="such-feld">
        <?= Icon::svg('search', 17) ?>
        <input class="eingabe" name="q" value="<?= Util::attr($begriff) ?>" autofocus
               placeholder="Kunde, Termin, Rechnung, Übung, Seite …" aria-label="Suchbegriff">
      </div>
    </form>
  </div>

  <?php if ($begriff === ''): ?>
    <div class="karte__koerper" style="border-top:1px solid var(--rand)">
      <?= leerzustand('search', 'Alles auf einmal durchsuchen',
            'Kunden, Termine, Rechnungen, Trainingspläne, Videos, Seiten und Beiträge – '
            . 'ein Begriff genügt. Mit Strg + K geht das von jeder Seite aus.', []) ?>
    </div>
  <?php elseif ($treffer === []): ?>
    <div class="karte__koerper" style="border-top:1px solid var(--rand)">
      <?= leerzustand('search', 'Nichts gefunden',
            'Für „' . $begriff . '“ gibt es keinen Treffer. Vielleicht hilft ein kürzerer Begriff – '
            . 'gesucht wird auch in Teilen von Wörtern.', []) ?>
    </div>
  <?php else:
    $gruppen = [];
    foreach ($treffer as $t) {
      $gruppen[$t['gruppe']][] = $t;
    }
    foreach ($gruppen as $gruppe => $liste): ?>
      <div style="border-top:1px solid var(--rand)">
        <div class="versal gedimmt-2" style="padding:var(--r3) var(--r5) 4px"><?= Util::h($gruppe) ?></div>
        <?php foreach ($liste as $t): ?>
          <a class="reihe" href="<?= Util::attr($t['url']) ?>"
             style="padding:9px var(--r5);color:inherit;border-top:1px solid var(--rand)">
            <span style="color:var(--text-4)"><?= $t['icon'] ?></span>
            <div style="flex:1;min-width:0">
              <div class="halbfett" style="font-size:13.5px"><?= Util::h($t['titel']) ?></div>
              <?php if ($t['unter'] !== ''): ?>
                <div class="klein gedimmt"><?= Util::h($t['unter']) ?></div>
              <?php endif; ?>
            </div>
            <?= Icon::svg('chevron-right', 15) ?>
          </a>
        <?php endforeach; ?>
      </div>
    <?php endforeach;
  endif; ?>
</div>

<?php require __DIR__ . '/partials/fuss.php'; ?>
