<?php
require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/partials/helfer.php';
Auth::fordern('modul.dashboard');

/*
 * Im Support Mode bleibt alles ungelesen. Die Meldungen gehören dem
 * Inhaber; hätte der Support sie beim Nachsehen abgehakt, fände er die
 * neue Rechnung nie an seiner Glocke.
 */
if (App::get('aktion') === 'alle_gelesen') {
    if (Support::vermerkt()) {
        App::melden('Im Support Mode bleiben Benachrichtigungen ungelesen – sie gehören dem Inhaber.', 'info');
    } else {
        Notify::alleGelesen(Auth::id());
        App::melden('Alle Benachrichtigungen als gelesen markiert.');
    }
    App::weiter('/app/benachrichtigungen.php');
}

/*
 * Eine Meldung öffnen: als gelesen markieren, dann zu ihrem Ziel.
 *
 * Bisher führte der Klick direkt zum Ziel, und die Meldung blieb
 * ungelesen, bis jemand „Alle gelesen" drückte – der Punkt an der Glocke
 * sagte damit nichts mehr. Weitergeleitet wird nur innerhalb von /app/:
 * Das Ziel steht zwar in der eigenen Datenbank, aber eine Weiterleitung,
 * die jede Adresse annimmt, ist eine Einladung.
 */
if (App::getInt('oeffnen') > 0) {
    $m = Notify::fuer(App::getInt('oeffnen'), Auth::id());
    if ($m === null) {
        App::weiter('/app/benachrichtigungen.php');
    }
    if (!$m['gelesen'] && !Support::vermerkt()) {
        Notify::alsGelesen((int) $m['id']);
    }
    $ziel = (string) $m['link'];
    $intern = preg_match('#^/app/[A-Za-z0-9._/-]*(\?[A-Za-z0-9._~%&=+,:-]*)?(\#[A-Za-z0-9_-]*)?$#', $ziel) === 1
        && !str_contains($ziel, '..') && !str_contains($ziel, '//');
    App::weiter($intern ? $ziel : '/app/benachrichtigungen.php');
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
  <?php foreach (Notify::KATEGORIEN as $key => [$name, $icon]):
    if (!Notify::kategorieSichtbar($key)) { continue; } ?>
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
      <a class="reihe" href="<?= Util::attr(App::url('/app/benachrichtigungen.php?oeffnen=' . (int) $m['id'])) ?>"
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
