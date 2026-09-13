<?php
/** SEO-Prüfung: was der Pro selbst beheben kann. */
require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/partials/helfer.php';

Auth::fordern('modul.website');

$ergebnis = SEO::pruefen();
$offen = array_values(array_filter($ergebnis['pruefungen'], static fn($p) => $p['status'] !== 'gut'));
$gut   = array_values(array_filter($ergebnis['pruefungen'], static fn($p) => $p['status'] === 'gut'));

$titel = 'SEO';
$unter = 'Was Suchmaschinen von deiner Website halten – und was du dagegen tun kannst.';
$brotkrumen = [['Website', '/app/website.php'], ['SEO', null]];
require __DIR__ . '/partials/kopf.php';
?>

<div class="raster raster--neben-haupt">
  <div class="stapel">
    <div class="karte">
      <div class="karte__koerper mitte">
        <?= Diagramm::ring($ergebnis['score'], 128,
              $ergebnis['score'] >= 70 ? 'var(--erfolg)' : ($ergebnis['score'] >= 40 ? 'var(--warnung)' : 'var(--gefahr)')) ?>
        <h2 class="mt-4"><?= $ergebnis['score'] >= 70 ? 'Gut aufgestellt'
              : ($ergebnis['score'] >= 40 ? 'Ausbaufähig' : 'Da geht mehr') ?></h2>
        <p class="klein gedimmt mt-2">
          <?= count($gut) ?> von <?= count($ergebnis['pruefungen']) ?> Prüfungen bestanden
        </p>
      </div>
    </div>

    <div class="hinweis hinweis--still">
      <?= Icon::svg('info', 17) ?>
      <div class="hinweis__text">
        <span class="hinweis__titel">Warum nur diese Punkte?</span>
        Geprüft wird, was nachweislich zählt und was du selbst ändern kannst: Titel,
        Beschreibung, eine Hauptüberschrift je Seite, Textumfang, lokale Angaben und
        Pflichtseiten. Für einen Golf Professional bringt die lokale Auffindbarkeit mehr
        als jede technische Feinheit.
      </div>
    </div>
  </div>

  <div class="stapel">
    <?php if ($offen !== []): ?>
    <div class="karte">
      <div class="karte__kopf"><h2>Zu tun</h2>
        <span class="pille pille--warnung"><?= count($offen) ?></span></div>
      <div class="karte__koerper karte__koerper--eng">
        <div class="stapel stapel--eng">
          <?php foreach ($offen as $p): ?>
            <div class="empfehlung">
              <div class="empfehlung__symbol empfehlung__symbol--<?= $p['status'] === 'schlecht' ? 'gefahr' : 'warnung' ?>">
                <?= Icon::svg($p['status'] === 'schlecht' ? 'alert' : 'info', 16) ?>
              </div>
              <div class="empfehlung__text">
                <div class="empfehlung__titel"><?= Util::h($p['titel']) ?>
                  <span class="gedimmt klein">· <?= Util::h($p['seite']) ?></span></div>
                <div class="empfehlung__grund"><?= Util::h($p['text']) ?></div>
              </div>
              <div class="empfehlung__aktion">
                <a class="btn btn--klein" href="<?= Util::attr(App::url($p['link'])) ?>">Beheben</a>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
    <?php endif; ?>

    <div class="karte">
      <div class="karte__kopf"><h2>Erledigt</h2>
        <span class="pille pille--erfolg"><?= count($gut) ?></span></div>
      <div class="karte__koerper karte__koerper--eng">
        <?php if ($gut === []): ?>
          <p class="klein gedimmt mitte" style="padding:var(--r3) 0">Noch nichts.</p>
        <?php else: ?>
          <div class="stapel stapel--eng">
            <?php foreach ($gut as $p): ?>
              <div class="reihe reihe--eng" style="padding:6px;font-size:13px">
                <span style="color:var(--erfolg)"><?= Icon::svg('check', 15) ?></span>
                <span class="halbfett"><?= Util::h($p['titel']) ?></span>
                <span class="gedimmt-2">· <?= Util::h($p['seite']) ?></span>
                <div class="fueller"></div>
                <span class="gedimmt klein nicht-mobil"><?= Util::h(Util::kuerzen($p['text'], 54)) ?></span>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <div class="karte">
      <div class="karte__kopf"><h3>Technisches</h3></div>
      <div class="karte__koerper karte__koerper--eng">
        <div class="stapel stapel--eng klein">
          <div class="reihe"><span class="gedimmt">Sitemap</span><div class="fueller"></div>
            <a href="<?= Util::attr(App::url('/sitemap.php?w=' . rawurlencode((string) (Tenant::workspace()['slug'] ?? '')))) ?>"
               target="_blank" rel="noopener">sitemap.xml</a></div>
          <div class="reihe"><span class="gedimmt">Strukturierte Daten</span><div class="fueller"></div>
            <span class="halbfett">eingebunden (SportsActivityLocation)</span></div>
          <div class="reihe"><span class="gedimmt">Bildformate</span><div class="fueller"></div>
            <span class="halbfett">lazy geladen</span></div>
          <div class="reihe"><span class="gedimmt">Besucherzählung</span><div class="fueller"></div>
            <span class="halbfett">cookiefrei, ohne IP-Speicherung</span></div>
        </div>
      </div>
    </div>
  </div>
</div>

<?php require __DIR__ . '/partials/fuss.php'; ?>
