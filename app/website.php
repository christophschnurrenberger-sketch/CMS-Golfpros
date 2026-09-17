<?php
/** Website-Übersicht: Seiten, Design, SEO und der Weg nach draußen. */
require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/partials/helfer.php';

Auth::fordern('modul.website');

if (App::istPost()) {
    Auth::csrfFordern();
    Auth::fordern('website.write');

    $aktion = App::aktion();
    $seiteId = App::postInt('seite_id');

    if (in_array($aktion, ['hoch', 'runter', 'einruecken', 'ausruecken'], true) && $seiteId > 0) {
        [$ok, $grund] = match ($aktion) {
            'hoch'       => [Pages::schieben($seiteId, -1), 'Weiter nach oben geht es nicht.'],
            'runter'     => [Pages::schieben($seiteId, 1), 'Weiter nach unten geht es nicht.'],
            'einruecken' => Pages::einruecken($seiteId),
            'ausruecken' => Pages::ausruecken($seiteId),
        };
        if (!$ok) {
            App::melden($grund, 'warnung');
        }
        App::weiter('/app/website.php#seite-' . $seiteId);
    }

    /* Ziehen und Fallenlassen meldet sich als ganz gewöhnlicher POST. */
    if ($aktion === 'baum_ablegen' && $seiteId > 0) {
        [$ok, $grund] = Pages::ablegen($seiteId, App::postInt('ziel_id'), App::post('modus', 'unter'));
        if (!$ok) {
            App::melden($grund, 'warnung');
        }
        App::weiter('/app/website.php#seite-' . $seiteId);
    }

    if ($aktion === 'ki_website') {
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
/*
 * Die normalen Seiten stehen als Baum da: in Baumreihenfolge, jede mit
 * ihrer Tiefe. Landingpages bleiben draußen – die hängen an einer Kampagne
 * und nicht im Menü, und ein Baum mit einem Ast, der nirgendwohin führt,
 * verwirrt mehr, als er ordnet.
 */
$normale = Pages::flach(array_values(array_filter($seiten,
    static fn($s) => (string) $s['art'] !== 'landingpage')));
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
        <span class="pille"><?= count($normale) ?></span>
        <div class="fueller"></div>
        <span class="klein gedimmt nicht-mobil">Ziehen ordnet ein – der Baum ist die Navigation</span>
      </div>
      <div class="tabelle-huelle">
        <?php
          $darf = Auth::darf('website.write');
          /* Für „hoch/runter": Wer ist der erste und wer der letzte unter
             seinen Geschwistern? Sonst bieten wir Knöpfe an, die nichts tun. */
          $geschwister = [];
          foreach ($normale as $s) { $geschwister[(int) $s['parent_id']][] = (int) $s['id']; }
          $nachId = [];
          foreach ($normale as $s) { $nachId[(int) $s['id']] = $s; }
        ?>
        <table class="tabelle tabelle--klickbar baumtabelle">
          <thead><tr>
            <?php if ($darf): ?><th class="baum__griff-spalte" aria-label="Ziehen"></th><?php endif; ?>
            <th class="baum__name">Seite</th><th class="nicht-mobil">Adresse</th>
            <th class="zahl nicht-mobil">Aufrufe</th><th>Status</th>
            <?php if ($darf): ?><th class="nicht-mobil">Ordnen</th><?php endif; ?>
            <th></th></tr></thead>
          <tbody<?= $darf ? ' data-baum="' . Util::attr(App::url('/app/website.php')) . '"' : '' ?>>
          <?php foreach ($normale as $s):
            $url    = App::url('/app/seite.php?id=' . (int) $s['id']);
            $tiefe  = (int) ($s['tiefe'] ?? 0);
            $linien = (array) ($s['linien'] ?? []);
            $reihe  = $geschwister[(int) $s['parent_id']] ?? [];
            $platz  = array_search((int) $s['id'], $reihe, true);
            $start  = (int) $s['startseite'] === 1;
            $eltern = $nachId[(int) $s['parent_id']] ?? null;
            $kinder = count(array_filter($normale,
                static fn($k) => (int) $k['parent_id'] === (int) $s['id']));
            /* Eine Unterseite kann nur dort entstehen, wo darunter noch
               eine Ebene frei ist. */
            $platzFuerKind = !$start && $tiefe + 1 < Pages::MAX_TIEFE; ?>
            <tr id="seite-<?= (int) $s['id'] ?>" data-id="<?= (int) $s['id'] ?>"
                data-tiefe="<?= $tiefe ?>" data-titel="<?= Util::attr((string) $s['titel']) ?>"
                <?= $start ? 'data-fest="1" ' : '' ?>
                onclick="location.href='<?= Util::attr($url) ?>'">
              <?php if ($darf): ?>
              <td class="baum__griff-spalte" onclick="event.stopPropagation()">
                <?php if (!$start): ?>
                  <span class="baum__griff" draggable="true" title="Ziehen zum Einordnen"
                        aria-hidden="true"><?= Icon::svg('grip', 14) ?></span>
                <?php endif; ?>
              </td>
              <?php endif; ?>
              <td class="baum__name">
                <span class="baumzeile">
                  <?php if ($tiefe > 0): ?>
                    <span class="baumzeile__spur" aria-hidden="true">
                      <?php foreach ($linien as $weiter): ?>
                        <span class="baumzeile__stufe<?= $weiter ? ' ist-linie' : '' ?>"></span>
                      <?php endforeach; ?>
                      <span class="baumzeile__knick<?= !empty($s['letztes']) ? ' ist-letztes' : '' ?>"></span>
                    </span>
                  <?php endif; ?>
                  <span class="baumzeile__text">
                    <span class="baumzeile__titel">
                      <span class="haupt"><?= Util::h((string) $s['titel']) ?></span>
                      <?php if ($start): ?><?= pille('Startseite', 'marke') ?><?php endif; ?>
                      <?php if ((int) $s['im_menue'] === 0 && !$start): ?>
                        <?= pille('nicht im Menü', '') ?>
                      <?php endif; ?>
                    </span>
                    <span class="winzig gedimmt-2 baumzeile__unter">
                      <?php
                        /* Die Zeile beantwortet in ihrer ersten Angabe die
                           Frage, die man vor einem Baum hat: Wo hängt die
                           hier? Alles Weitere kommt danach. */
                        $teile = [];
                        if ($eltern !== null) {
                            $teile[] = 'unter <strong>' . Util::h((string) $eltern['titel']) . '</strong>';
                        } elseif (!$start) {
                            $teile[] = 'Hauptpunkt';
                        }
                        if ($kinder > 0) {
                            $teile[] = $kinder . ' ' . ($kinder === 1 ? 'Unterseite' : 'Unterseiten');
                        }
                        $teile[] = count(Pages::bloecke($s)) . ' Bausteine';
                        $teile[] = Util::h(Util::relativ((string) $s['geaendert']));
                        echo implode(' · ', $teile);
                      ?></span>
                  </span>
                </span>
              </td>
              <td class="nicht-mobil mono gedimmt">/<?= Util::h((string) $s['slug']) ?></td>
              <td class="zahl nicht-mobil tabnum"><?= Util::zahl((int) $s['aufrufe']) ?></td>
              <td><?= pille((string) $s['status'] === 'veroeffentlicht' ? 'Live' : 'Entwurf',
                    (string) $s['status'] === 'veroeffentlicht' ? 'erfolg' : '') ?></td>
              <?php if ($darf): ?>
              <td class="nicht-mobil aktionen" onclick="event.stopPropagation()">
                <?php
                  /* Die Startseite ist die Marke oben links und steht nicht
                     im Baum – bei ihr führen diese Knöpfe zu nichts. */
                  $knoepfe = $start ? [] : [
                    ['hoch', 'chevron-up', 'Nach oben', $platz !== false && $platz > 0],
                    ['runter', 'chevron-down', 'Nach unten', $platz !== false && $platz < count($reihe) - 1],
                    ['einruecken', 'chevron-right', 'Eine Ebene tiefer', $platz !== false && $platz > 0],
                    ['ausruecken', 'chevron-left', 'Eine Ebene höher', $tiefe > 0],
                  ];
                  foreach ($knoepfe as [$aktion, $icon, $label, $moeglich]):
                    if (!$moeglich): ?>
                      <span class="btn btn--klein" aria-hidden="true"
                            style="opacity:.25;pointer-events:none"><?= Icon::svg($icon, 14) ?></span>
                    <?php continue; endif; ?>
                  <form method="post" style="display:inline">
                    <?= Auth::csrfFeld() ?>
                    <input type="hidden" name="aktion" value="<?= Util::attr($aktion) ?>">
                    <input type="hidden" name="seite_id" value="<?= (int) $s['id'] ?>">
                    <button class="btn btn--klein" type="submit"
                            title="<?= Util::attr($label) ?>" aria-label="<?= Util::attr($label . ': ' . $s['titel']) ?>">
                      <?= Icon::svg($icon, 14) ?></button>
                  </form>
                <?php endforeach; ?>
              </td>
              <?php endif; ?>
              <td class="aktionen" onclick="event.stopPropagation()">
                <?php if ($darf && $platzFuerKind): ?>
                  <a class="btn btn--klein" title="Unterseite anlegen"
                     aria-label="Unterseite von <?= Util::attr((string) $s['titel']) ?> anlegen"
                     href="<?= Util::attr(App::url('/app/seite.php?id=neu&eltern=' . (int) $s['id'])) ?>">
                    <?= Icon::svg('plus', 14) ?></a>
                <?php endif; ?>
                <a class="btn btn--klein" target="_blank" rel="noopener"
                   href="<?= Util::attr(Pages::url($s)) ?>" aria-label="Ansehen">
                  <?= Icon::svg('external', 14) ?></a>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if ($normale === []): ?>
            <tr class="tabelle-leer"><td colspan="7">Noch keine Seite angelegt.</td></tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
      <?php if ($darf && count($normale) > 1): ?>
      <div class="karte__fuss">
        <div class="klein gedimmt">
          <?= Icon::svg('info', 14) ?>
          Am Griff ziehen: <strong>mitten auf eine Zeile</strong> macht die Seite zu deren
          Unterseite, <strong>an den Rand</strong> setzt sie daneben. Das <?= Icon::svg('plus', 12) ?>
          legt gleich eine Unterseite an. Bis zu <?= Pages::MAX_TIEFE ?> Ebenen.
        </div>
      </div>
      <?php endif; ?>
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
