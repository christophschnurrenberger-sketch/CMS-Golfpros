<?php
/** Website-Übersicht: Seiten, Design, SEO und der Weg nach draußen. */
require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/partials/helfer.php';

Auth::fordern('modul.website');

if (App::istPost()) {
    Auth::csrfFordern();
    Auth::fordern('website.write');

    if (App::aktion() === 'ki_website') {
        $entwurf = KI::websiteEntwurf(App::post('beschreibung'), Tenant::name());
        $start = Pages::startseite();
        if ($start) {
            Pages::speichern([
                'bloecke' => Util::json($entwurf['bloecke']),
                'seo' => Util::json($entwurf['seo']),
            ], (int) $start['id']);
            App::melden($entwurf['hinweis'] . ' Prüfe die Texte, bevor du veröffentlichst.', 'info');
            App::weiter('/app/seite.php?id=' . (int) $start['id']);
        }
        $neu = Pages::speichern([
            'titel' => 'Startseite', 'slug' => 'start',
            'bloecke' => Util::json($entwurf['bloecke']),
            'seo' => Util::json($entwurf['seo']),
            'status' => 'entwurf',
        ]);
        Pages::startseiteSetzen($neu);
        App::melden($entwurf['hinweis'], 'info');
        App::weiter('/app/seite.php?id=' . $neu);
    }
}

$seiten = Pages::alle();
$landingpages = array_values(array_filter($seiten, static fn($s) => (string) $s['art'] === 'landingpage'));
$normale = array_values(array_filter($seiten, static fn($s) => (string) $s['art'] !== 'landingpage'));
$veroeffentlicht = count(array_filter($seiten, static fn($s) => (string) $s['status'] === 'veroeffentlicht'));
$seoWert = SEO::pruefen();
$besucher30 = Analytics::besucher(date('Y-m-d', strtotime('-29 days')), Util::heute());
$domain = (string) (Tenant::workspace()['domain'] ?? '');

$titel = 'Website';
$unter = count($seiten) . ' Seiten · ' . $veroeffentlicht . ' veröffentlicht · '
       . Util::zahl($besucher30) . ' Besucher in 30 Tagen';
$aktionen = '<a class="btn" target="_blank" rel="noopener" href="' . App::url('/app/vorschau.php') . '">'
          . Icon::svg('external', 15) . ' Ansehen</a>'
          . '<a class="btn" href="' . App::url('/app/design.php') . '">' . Icon::svg('palette', 15) . ' Design</a>'
          . (Auth::darf('website.write')
             ? '<a class="btn btn--primaer" href="' . App::url('/app/seite.php?id=neu') . '">'
               . Icon::svg('plus', 15) . ' Neue Seite</a>' : '');
require __DIR__ . '/partials/kopf.php';
?>

<?php if ($seiten === []): ?>
  <div class="karte mb-5">
    <div class="karte__koerper">
      <?= leerzustand('website', 'Deine Website beginnt hier',
            'Beschreib in einem Satz, was du anbietest – daraus entsteht eine vollständige '
            . 'Startseite mit Texten, Leistungen, Stimmen, FAQ und Buchungskalender. '
            . 'Jeder Baustein bleibt bearbeitbar.', []) ?>
    </div>
  </div>
<?php endif; ?>

<div class="raster raster--4 mb-5">
  <?= kennzahl('Besucher', Util::zahl($besucher30), ['icon' => 'customers', 'fuss' => 'letzte 30 Tage',
        'url' => '/app/auswertung.php']) ?>
  <?= kennzahl('Seitenaufrufe', Util::zahl(Analytics::seitenaufrufe(date('Y-m-d', strtotime('-29 days')), Util::heute())),
        ['icon' => 'eye', 'fuss' => 'letzte 30 Tage']) ?>
  <?= kennzahl('SEO-Wert', $seoWert['score'] . ' / 100', ['icon' => 'target',
        'fuss' => count(array_filter($seoWert['pruefungen'], static fn($p) => $p['status'] !== 'gut')) . ' Punkte offen',
        'url' => '/app/seo.php']) ?>
  <?= kennzahl('Domain', $domain !== '' ? $domain : 'keine', ['icon' => 'globe',
        'fuss' => $domain !== '' ? 'eingerichtet'
          : '<a href="' . App::url('/app/einstellungen.php') . '">einrichten</a>']) ?>
</div>

<div class="raster raster--haupt-neben">
  <div class="stapel">
    <div class="karte">
      <div class="karte__kopf"><h2>Seiten</h2>
        <span class="pille"><?= count($normale) ?></span></div>
      <div class="tabelle-huelle">
        <table class="tabelle tabelle--klickbar">
          <thead><tr><th>Seite</th><th class="nicht-mobil">Adresse</th>
            <th class="zahl nicht-mobil">Aufrufe</th><th>Status</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($normale as $s):
            $url = App::url('/app/seite.php?id=' . (int) $s['id']); ?>
            <tr onclick="location.href='<?= Util::attr($url) ?>'">
              <td>
                <span class="haupt"><?= Util::h((string) $s['titel']) ?></span>
                <?php if ((int) $s['startseite'] === 1): ?>
                  <?= pille('Startseite', 'marke') ?>
                <?php endif; ?>
                <div class="winzig gedimmt-2">
                  <?= count(Pages::bloecke($s)) ?> Bausteine ·
                  geändert <?= Util::h(Util::relativ((string) $s['geaendert'])) ?></div>
              </td>
              <td class="nicht-mobil mono gedimmt">/<?= Util::h((string) $s['slug']) ?></td>
              <td class="zahl nicht-mobil tabnum"><?= Util::zahl((int) $s['aufrufe']) ?></td>
              <td><?= pille((string) $s['status'] === 'veroeffentlicht' ? 'Live' : 'Entwurf',
                    (string) $s['status'] === 'veroeffentlicht' ? 'erfolg' : '') ?></td>
              <td class="aktionen">
                <a class="btn btn--klein" target="_blank" rel="noopener" onclick="event.stopPropagation()"
                   href="<?= Util::attr(Pages::url($s)) ?>" aria-label="Ansehen">
                  <?= Icon::svg('external', 14) ?></a>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if ($normale === []): ?>
            <tr class="tabelle-leer"><td colspan="5">Noch keine Seite angelegt.</td></tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <div class="karte">
      <div class="karte__kopf"><h2>Landingpages</h2>
        <span class="pille"><?= count($landingpages) ?></span>
        <div class="fueller"></div>
        <?php if (Auth::darf('website.write')): ?>
          <button class="btn btn--klein btn--primaer" data-modal-auf="modal-landing">
            <?= Icon::svg('ai', 14) ?> Mit KI erstellen</button>
        <?php endif; ?>
      </div>
      <?php if ($landingpages === []): ?>
        <div class="karte__koerper">
          <?= leerzustand('target', 'Noch keine Landingpage',
                'Eine Landingpage hat genau ein Ziel und kein Menü, das ablenkt – ideal für einen '
                . 'Kurs, ein Event oder eine Anzeige. Beschreib das Angebot in einem Satz, den Rest '
                . 'erledigt der Entwurf.', []) ?>
        </div>
      <?php else: ?>
        <div class="tabelle-huelle">
          <table class="tabelle tabelle--klickbar">
            <thead><tr><th>Landingpage</th><th class="zahl nicht-mobil">Aufrufe</th>
              <th class="zahl">Anfragen</th><th>Conversion</th></tr></thead>
            <tbody>
            <?php foreach ($landingpages as $s):
              $quote = Pages::conversion($s);
              $url = App::url('/app/seite.php?id=' . (int) $s['id']); ?>
              <tr onclick="location.href='<?= Util::attr($url) ?>'">
                <td class="haupt"><?= Util::h((string) $s['titel']) ?>
                  <div class="winzig gedimmt-2">/<?= Util::h((string) $s['slug']) ?></div></td>
                <td class="zahl nicht-mobil tabnum"><?= Util::zahl((int) $s['aufrufe']) ?></td>
                <td class="zahl tabnum"><?= (int) $s['conversions'] ?></td>
                <td>
                  <?php if ($quote === null): ?>
                    <span class="gedimmt-2 klein">zu wenige Aufrufe</span>
                  <?php else: ?>
                    <?= pille(Util::prozent($quote, 1), $quote >= 2 ? 'erfolg' : ($quote >= 1 ? 'warnung' : 'gefahr')) ?>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <div class="stapel">
    <?php if (Auth::darf('website.write')): ?>
    <div class="ki-block">
      <div class="ki-block__kopf"><?= Icon::svg('ai', 15) ?> Website erzeugen</div>
      <form method="post">
        <?= Auth::csrfFeld() ?>
        <input type="hidden" name="aktion" value="ki_website">
        <div class="feld">
          <textarea class="eingabe" name="beschreibung" rows="3" data-waechst required
            placeholder="Ich bin Golf Professional mit Schwerpunkt Anfänger und Platzreife."></textarea>
        </div>
        <button class="btn btn--primaer btn--voll" type="submit">
          <?= Icon::svg('wand', 15) ?> Startseite entwerfen</button>
      </form>
      <div class="ki-warnung mt-3"><?= Icon::svg('info', 13) ?>
        Überschreibt die Bausteine der Startseite. Der Entwurf bleibt bearbeitbar und ist
        nicht veröffentlicht.</div>
    </div>
    <?php endif; ?>

    <div class="karte">
      <div class="karte__kopf"><h3>Werkzeuge</h3></div>
      <div class="karte__koerper karte__koerper--eng">
        <div class="stapel stapel--eng">
          <?php foreach ([
            ['Design und Farben', 'Marke, Schrift, Rundungen und Stil', 'palette', '/app/design.php'],
            ['SEO prüfen', $seoWert['score'] . ' von 100 Punkten', 'target', '/app/seo.php'],
            ['Mediathek', Tenant::count('media') . ' Dateien', 'image', '/app/medien.php'],
            ['Blog und Beiträge', Tenant::count('posts') . ' Beiträge', 'content', '/app/inhalte.php'],
            ['Formulare', Tenant::count('forms') . ' Formulare', 'inbox', '/app/marketing.php'],
          ] as [$name, $text, $icon, $url]): ?>
            <a class="kachel-link" href="<?= Util::attr(App::url($url)) ?>">
              <span class="kachel-link__symbol" style="width:32px;height:32px"><?= Icon::svg($icon, 15) ?></span>
              <div style="flex:1;min-width:0">
                <div class="kachel-link__titel"><?= Util::h($name) ?></div>
                <div class="kachel-link__text"><?= Util::h($text) ?></div>
              </div>
              <?= Icon::svg('chevron-right', 15) ?>
            </a>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

    <?php if ($domain === ''): ?>
      <div class="hinweis hinweis--still">
        <?= Icon::svg('globe', 17) ?>
        <div class="hinweis__text">
          <span class="hinweis__titel">Noch keine eigene Domain</span>
          Die Seite läuft unter der Adresse der Installation. Eine eigene Domain wirkt seriöser
          und rankt besser – einzutragen unter Einstellungen.
        </div>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php if (Auth::darf('website.write')): ?>
<dialog class="modal modal--schmal" id="modal-landing">
  <form method="get" action="<?= Util::attr(App::url('/app/seite.php')) ?>">
    <input type="hidden" name="id" value="neu">
    <input type="hidden" name="art" value="landingpage">
    <input type="hidden" name="vorlage" value="landing">
    <div class="modal__kopf"><h2>Landingpage erstellen</h2>
      <button type="button" class="rundknopf" data-modal-zu aria-label="Schließen"><?= Icon::svg('x', 17) ?></button></div>
    <div class="modal__koerper">
      <div class="feld"><label class="feld__label" for="lp-titel">Titel</label>
        <input class="eingabe" id="lp-titel" name="titel" required value="Platzreife im Frühjahr"></div>
      <div class="feld"><label class="feld__label" for="lp-prompt">Worum geht es?</label>
        <textarea class="eingabe" id="lp-prompt" name="prompt" rows="3" data-waechst
          placeholder="Erstelle eine Landingpage für einen Platzreife-Kurs im Frühjahr.">Platzreife-Kurs im Frühjahr für Anfänger</textarea></div>
      <div class="hinweis hinweis--still">
        <?= Icon::svg('info', 16) ?>
        <div class="hinweis__text">Es entsteht ein Entwurf mit Titelbereich, Vorteilen, Angebot,
          Stimmen, FAQ und Buchung – ohne Menü, damit nichts vom Ziel ablenkt.</div>
      </div>
    </div>
    <div class="modal__fuss"><div class="fueller"></div>
      <button type="button" class="btn" data-modal-zu>Abbrechen</button>
      <button type="submit" class="btn btn--primaer">Entwerfen</button></div>
  </form>
</dialog>
<?php endif; ?>

<?php require __DIR__ . '/partials/fuss.php'; ?>
