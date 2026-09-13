<?php
/** Blog und Beiträge – Inhalte, die Besucher bringen, bevor sie suchen. */
require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/partials/helfer.php';

Auth::fordern('modul.content');

if (App::istPost()) {
    Auth::csrfFordern();
    Auth::fordern('content.write');

    if (App::aktion() === 'ki_ideen') {
        $ideen = KI::blogideen(6);
        Tenant::einstellungSetzen('blogideen', $ideen);
        App::melden('Sechs Vorschläge erstellt. Ein Klick macht daraus einen Entwurf.', 'info');
    }
    if (App::aktion() === 'aus_idee') {
        $id = Tenant::insert('posts', [
            'titel' => App::post('titel'), 'slug' => Util::slug(App::post('titel')),
            'auszug' => App::post('auszug'), 'user_id' => Auth::id(),
            'status' => 'entwurf', 'quelle' => 'ai',
        ]);
        App::weiter('/app/beitrag.php?id=' . $id);
    }
    App::weiter('/app/inhalte.php');
}

$status = App::get('status');
$beitraege = Tenant::all('posts', $status !== '' ? 'status = :s' : '',
    $status !== '' ? ['s' => $status] : [], 'veroeffentlicht DESC, id DESC');
$ideen = Tenant::einstellung('blogideen', []);

$titel = 'Inhalte';
$unter = count($beitraege) . ' Beiträge · '
       . Tenant::count('posts', 'status = "veroeffentlicht"') . ' veröffentlicht';
$aktionen = '<a class="btn" href="' . App::url('/app/medien.php') . '">' . Icon::svg('image', 15) . ' Mediathek</a>'
          . (Auth::darf('content.write')
             ? '<a class="btn btn--primaer" href="' . App::url('/app/beitrag.php?id=neu') . '">'
               . Icon::svg('plus', 15) . ' Beitrag schreiben</a>' : '');
require __DIR__ . '/partials/kopf.php';
?>

<div class="raster raster--haupt-neben">
  <div class="karte">
    <div class="reiter" style="margin:0;padding:var(--r2) var(--r4) 0">
      <a class="reiter__teil<?= $status === '' ? ' ist-aktiv' : '' ?>"
         href="<?= Util::attr(App::url('/app/inhalte.php')) ?>">Alle</a>
      <a class="reiter__teil<?= $status === 'veroeffentlicht' ? ' ist-aktiv' : '' ?>"
         href="<?= Util::attr(App::url('/app/inhalte.php?status=veroeffentlicht')) ?>">Veröffentlicht</a>
      <a class="reiter__teil<?= $status === 'entwurf' ? ' ist-aktiv' : '' ?>"
         href="<?= Util::attr(App::url('/app/inhalte.php?status=entwurf')) ?>">Entwürfe</a>
    </div>

    <?php if ($beitraege === []): ?>
      <div class="karte__koerper">
        <?= leerzustand('content', 'Noch kein Beitrag',
              'Ein Blog bringt Besucher, die noch nicht nach dir suchen, sondern nach ihrem '
              . 'Problem: „Slice abstellen“, „Platzreife Kosten“, „Putten üben“. Wer diese Fragen '
              . 'beantwortet, wird gefunden.',
              Auth::darf('content.write') ? [['Ersten Beitrag schreiben', '/app/beitrag.php?id=neu', 'plus']] : []) ?>
      </div>
    <?php else: ?>
      <div class="tabelle-huelle">
        <table class="tabelle tabelle--klickbar">
          <thead><tr><th>Beitrag</th><th class="nicht-mobil">Veröffentlicht</th>
            <th class="zahl nicht-mobil">Aufrufe</th><th>Status</th></tr></thead>
          <tbody>
          <?php foreach ($beitraege as $b):
            $url = App::url('/app/beitrag.php?id=' . (int) $b['id']); ?>
            <tr onclick="location.href='<?= Util::attr($url) ?>'">
              <td>
                <span class="haupt"><?= Util::h((string) $b['titel']) ?></span>
                <?php if ((string) $b['quelle'] === 'ai'): ?>
                  <span class="pille pille--lila"><?= Icon::svg('ai', 11) ?> KI</span>
                <?php endif; ?>
                <div class="winzig gedimmt-2"><?= Util::h(Util::kuerzen((string) $b['auszug'], 80)) ?></div>
              </td>
              <td class="nicht-mobil gedimmt">
                <?= $b['veroeffentlicht'] ? Util::h(Util::datum((string) $b['veroeffentlicht'])) : '—' ?></td>
              <td class="zahl nicht-mobil tabnum"><?= Util::zahl((int) $b['aufrufe']) ?></td>
              <td><?= pille((string) $b['status'] === 'veroeffentlicht' ? 'Live' : 'Entwurf',
                    (string) $b['status'] === 'veroeffentlicht' ? 'erfolg' : '') ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

  <div class="stapel">
    <?php if (Auth::darf('content.write')): ?>
    <div class="ki-block">
      <div class="ki-block__kopf"><?= Icon::svg('ai', 15) ?> Themenvorschläge</div>
      <?php if ($ideen === []): ?>
        <div class="ki-block__text klein">Sechs Themen, die bei Golf-Suchanfragen tatsächlich
          vorkommen – als Ausgangspunkt, nicht als fertiger Text.</div>
        <form method="post" class="mt-3">
          <?= Auth::csrfFeld() ?>
          <input type="hidden" name="aktion" value="ki_ideen">
          <button class="btn btn--primaer btn--voll" type="submit">
            <?= Icon::svg('wand', 15) ?> Ideen vorschlagen</button>
        </form>
      <?php else: ?>
        <div class="stapel stapel--eng">
          <?php foreach ($ideen as $i): ?>
            <form method="post">
              <?= Auth::csrfFeld() ?>
              <input type="hidden" name="aktion" value="aus_idee">
              <input type="hidden" name="titel" value="<?= Util::attr((string) $i['titel']) ?>">
              <input type="hidden" name="auszug" value="<?= Util::attr((string) $i['auszug']) ?>">
              <button type="submit" style="width:100%;text-align:left;background:var(--flaeche);
                      border:1px solid var(--rand);border-radius:var(--radius);padding:11px 13px;
                      cursor:pointer;font:inherit;color:inherit">
                <span class="klein halbfett" style="display:block"><?= Util::h((string) $i['titel']) ?></span>
                <span class="winzig gedimmt" style="display:block;margin-top:3px">
                  <?= Util::h(Util::kuerzen((string) $i['auszug'], 90)) ?></span>
              </button>
            </form>
          <?php endforeach; ?>
        </div>
        <form method="post" class="mt-3">
          <?= Auth::csrfFeld() ?>
          <input type="hidden" name="aktion" value="ki_ideen">
          <button class="btn btn--voll btn--klein" type="submit">
            <?= Icon::svg('refresh', 14) ?> Andere Vorschläge</button>
        </form>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <div class="karte">
      <div class="karte__kopf"><h3>Meistgelesen</h3></div>
      <div class="karte__koerper karte__koerper--eng">
        <?php
        $top = Tenant::all('posts', 'status = "veroeffentlicht"', [], 'aufrufe DESC', 5);
        if ($top === []): ?>
          <p class="klein gedimmt mitte" style="padding:var(--r3) 0">Noch keine Daten.</p>
        <?php else:
          $max = max(array_map(static fn($b) => (int) $b['aufrufe'], $top)); ?>
          <div class="rangliste">
            <?php foreach ($top as $b): ?>
              <div class="rang">
                <span class="rang__name"><?= Util::h(Util::kuerzen((string) $b['titel'], 40)) ?></span>
                <span class="rang__wert"><?= Util::zahl((int) $b['aufrufe']) ?></span>
                <span class="rang__spur"><span class="rang__fuellung"
                      data-breite="<?= (int) round((int) $b['aufrufe'] / max(1, $max) * 100) ?>%"></span></span>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<?php require __DIR__ . '/partials/fuss.php'; ?>
