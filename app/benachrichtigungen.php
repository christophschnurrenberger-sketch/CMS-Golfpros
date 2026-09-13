<?php
require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/partials/helfer.php';
Auth::fordern('modul.dashboard');

if (App::get('aktion') === 'alle_gelesen') {
    Notify::alleGelesen(Auth::id());
    App::melden('Alle Benachrichtigungen als gelesen markiert.');
    App::weiter('/app/benachrichtigungen.php');
}

$kategorie = App::get('kategorie');
$alle = Notify::fuerBenutzer(Auth::id(), 120);
if ($kategorie !== '') {
    $alle = array_values(array_filter($alle, static fn($m) => (string) $m['kategorie'] === $kategorie));
}

$titel = 'Benachrichtigungen';
$unter = Notify::ungelesen(Auth::id()) . ' ungelesen';
$aktionen = '<a class="btn" href="' . App::url('/app/benachrichtigungen.php?aktion=alle_gelesen') . '">'
          . Icon::svg('check', 15) . ' Alle als gelesen</a>';
require __DIR__ . '/partials/kopf.php';
?>

<div class="reiter">
  <a class="reiter__teil<?= $kategorie === '' ? ' ist-aktiv' : '' ?>"
     href="<?= Util::attr(App::url('/app/benachrichtigungen.php')) ?>">Alle</a>
  <?php foreach (Notify::KATEGORIEN as $key => [$name, $icon]): ?>
    <a class="reiter__teil<?= $kategorie === $key ? ' ist-aktiv' : '' ?>"
       href="<?= Util::attr(App::url('/app/benachrichtigungen.php?kategorie=' . $key)) ?>">
      <?= Icon::svg($icon, 15) ?> <?= Util::h($name) ?></a>
  <?php endforeach; ?>
</div>

<div class="karte" style="max-width:780px">
  <?php if ($alle === []): ?>
    <div class="karte__koerper">
      <?= leerzustand('bell', 'Keine Benachrichtigungen',
            'Hier landen Buchungen, Zahlungen, neue Anfragen und Empfehlungen aus deinen Daten.', []) ?>
    </div>
  <?php else: ?>
    <?php foreach ($alle as $m): ?>
      <a class="reihe" href="<?= Util::attr(App::url((string) ($m['link'] ?: '/app/'))) ?>"
         style="padding:var(--r3) var(--r5);color:inherit;border-bottom:1px solid var(--rand);align-items:flex-start<?= $m['gelesen'] ? '' : ';background:var(--marke-hell)' ?>">
        <span class="kachel-link__symbol" style="width:32px;height:32px;border-radius:9px">
          <?= Icon::svg(Notify::kategorieIcon((string) $m['kategorie']), 16) ?></span>
        <div style="flex:1;min-width:0">
          <div class="halbfett" style="font-size:13.5px"><?= Util::h((string) $m['titel']) ?></div>
          <?php if ($m['text']): ?>
            <div class="klein gedimmt"><?= Util::h((string) $m['text']) ?></div>
          <?php endif; ?>
          <div class="winzig gedimmt-2 mt-2">
            <?= Util::h(Notify::kategorieName((string) $m['kategorie'])) ?> ·
            <?= Util::h(Util::relativ((string) $m['erstellt'])) ?>
          </div>
        </div>
        <?php if (!$m['gelesen']): ?><span class="pille pille--marke">neu</span><?php endif; ?>
      </a>
    <?php endforeach; ?>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/partials/fuss.php'; ?>
