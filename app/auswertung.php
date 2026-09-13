<?php
/**
 * Auswertung – die Zahlen des Geschäfts.
 *
 * Jede Kennzahl wird aus den Belegen gerechnet, nicht gespeichert. Und jede
 * bekommt einen Vergleich: Eine Zahl ohne Bezugsgröße sagt nichts.
 */
require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/partials/helfer.php';

Auth::fordern('modul.analytics');

$zeit = App::get('zeit', '30tage');
$z = Analytics::zeitraum($zeit);
$k = Analytics::kennzahlen($z);
$verlauf = Analytics::umsatzVerlauf($z['von'], $z['bis']);
$jahr = Analytics::jahresvergleich();
$retention = Analytics::retention();
$bestseller = Commerce::bestseller(8, max(30, (int) ceil((strtotime($z['bis']) - strtotime($z['von'])) / 86400)));
$nachArt = Analytics::umsatzNachArt($z['von'], $z['bis']);
$quellen = Analytics::quellen($z['von'], $z['bis']);
$topSeiten = Analytics::topSeiten($z['von'], $z['bis']);
$besucherVerlauf = Analytics::besucherVerlauf(date('Y-m-d', strtotime('-29 days')), Util::heute());
$preisempfehlungen = Empfehlungen::preisempfehlungen();

$titel = 'Auswertung';
$unter = $z['name'] . ' · ' . Util::datum($z['von']) . ' bis ' . Util::datum($z['bis']);
$aktionen = '<form method="get" style="display:inline"><select name="zeit" data-auto-absenden aria-label="Zeitraum">';
foreach (Analytics::ZEITRAEUME as $key => $v) {
    $aktionen .= '<option value="' . $key . '"' . ($zeit === $key ? ' selected' : '') . '>' . Util::h($v) . '</option>';
}
$aktionen .= '</select></form>';
require __DIR__ . '/partials/kopf.php';
?>

<div class="raster raster--4 mb-5">
  <?= kennzahl('Umsatz', Analytics::formatieren($k['umsatz']), [
        'icon' => 'euro', 'delta' => $k['umsatz']['delta'],
        'fuss' => 'vs. ' . Util::datum($z['vergleich_von'], false) . '–' . Util::datum($z['vergleich_bis'], false),
        'kurve' => $verlauf['werte']]) ?>
  <?= kennzahl('Buchungen', Analytics::formatieren($k['buchungen']), [
        'icon' => 'bookings', 'delta' => $k['buchungen']['delta'], 'fuss' => 'Termine im Zeitraum']) ?>
  <?= kennzahl('Ø Bestellwert', Analytics::formatieren($k['warenkorb']), [
        'icon' => 'products', 'delta' => $k['warenkorb']['delta'], 'fuss' => 'je Bestellung']) ?>
  <?= kennzahl('Auslastung', $k['auslastung']['messbar'] ? Analytics::formatieren($k['auslastung']) : '—', [
        'icon' => 'activity',
        'fuss' => $k['auslastung']['messbar'] ? 'der Arbeitszeit'
          : '<a href="' . App::url('/app/verfuegbarkeit.php') . '">Arbeitszeiten fehlen</a>']) ?>
</div>

<div class="raster raster--4 mb-5">
  <?= kennzahl('Neue Kunden', Analytics::formatieren($k['neukunden']), [
        'icon' => 'user-plus', 'delta' => $k['neukunden']['delta']]) ?>
  <?= kennzahl('Kunden gesamt', Analytics::formatieren($k['kunden']), [
        'icon' => 'customers', 'fuss' => 'aktiv']) ?>
  <?= kennzahl('Kundenwert', Util::geld(Analytics::kundenwert()), [
        'icon' => 'trend-up', 'fuss' => 'Umsatz je Kunde, gesamt']) ?>
  <?= kennzahl('Bindung', $retention['quote'] !== null ? Util::prozent($retention['quote'], 1) : '—', [
        'icon' => 'heart',
        'fuss' => $retention['quote'] !== null
          ? $retention['aktiv'] . ' von ' . $retention['alt'] . ' noch aktiv'
          : 'zu wenig Historie']) ?>
</div>

<div class="raster raster--haupt-neben">
  <div class="stapel">
    <div class="karte">
      <div class="karte__kopf"><h2>Umsatzverlauf</h2>
        <div class="fueller"></div>
        <span class="klein gedimmt"><?= Util::h($z['name']) ?></span></div>
      <div class="karte__koerper">
        <?= Diagramm::saeulen($verlauf['labels'], $verlauf['werte'], ['format' => 'geld', 'hoehe' => 250]) ?>
      </div>
    </div>

    <div class="karte">
      <div class="karte__kopf"><h2>Dieses Jahr gegen letztes Jahr</h2></div>
      <div class="karte__koerper">
        <?= Diagramm::saeulen($jahr['labels'], $jahr['jetzt'],
              ['format' => 'geld', 'hoehe' => 250, 'vergleich' => $jahr['vorjahr']]) ?>
        <div class="legende mt-4">
          <span class="legende__teil"><span class="legende__farbe"></span> <?= date('Y') ?></span>
          <span class="legende__teil"><span class="legende__farbe" style="background:var(--rand-2)"></span>
            <?= date('Y') - 1 ?></span>
        </div>
      </div>
    </div>

    <div class="raster raster--2">
      <div class="karte">
        <div class="karte__kopf"><h3>Was Umsatz bringt</h3></div>
        <div class="karte__koerper">
          <?php if ($bestseller === []): ?>
            <p class="klein gedimmt mitte">Keine Verkäufe im Zeitraum.</p>
          <?php else:
            $max = max(array_map(static fn($b) => $b['umsatz'], $bestseller)); ?>
            <div class="rangliste">
              <?php foreach ($bestseller as $b): ?>
                <div class="rang">
                  <span class="rang__name"><?= Util::h($b['titel']) ?></span>
                  <span class="rang__wert"><?= Util::h(Util::geldKurz($b['umsatz'])) ?>
                    <span class="gedimmt klein"><?= $b['anzahl'] ?>×</span></span>
                  <span class="rang__spur"><span class="rang__fuellung"
                        data-breite="<?= (int) round($b['umsatz'] / max(1, $max) * 100) ?>%"></span></span>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <div class="karte">
        <div class="karte__kopf"><h3>Umsatz nach Art</h3></div>
        <div class="karte__koerper">
          <?php if ($nachArt === []): ?>
            <p class="klein gedimmt mitte">Keine Daten.</p>
          <?php else: ?>
            <div class="reihe" style="gap:var(--r5);align-items:center;flex-wrap:wrap">
              <?= Diagramm::donut(array_map(static fn($a) => [
                    'name' => $a['name'], 'wert' => (float) $a['wert'], 'farbe' => $a['farbe'],
                  ], $nachArt), 150) ?>
              <div class="stapel stapel--eng" style="flex:1;min-width:150px">
                <?php $summe = array_sum(array_column($nachArt, 'wert'));
                foreach ($nachArt as $a): ?>
                  <div class="reihe reihe--eng klein">
                    <span class="legende__farbe" style="background:<?= Util::attr($a['farbe']) ?>"></span>
                    <span><?= Util::h($a['name']) ?></span>
                    <div class="fueller"></div>
                    <span class="halbfett tabnum"><?= Util::h(Util::geldKurz($a['wert'])) ?></span>
                  </div>
                <?php endforeach; ?>
              </div>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <div class="karte" id="website">
      <div class="karte__kopf"><h2>Website</h2>
        <div class="fueller"></div>
        <span class="klein gedimmt">letzte 30 Tage · cookiefreie Zählung</span></div>
      <div class="karte__koerper">
        <?= Diagramm::linie($besucherVerlauf['labels'], ['Besucher' => $besucherVerlauf['werte']],
              ['hoehe' => 200]) ?>
        <div class="raster raster--2 mt-5">
          <div>
            <div class="versal gedimmt-2 mb-3">Woher die Besucher kommen</div>
            <?php if ($quellen === []): ?>
              <p class="klein gedimmt">Keine Daten.</p>
            <?php else:
              $max = max(array_map(static fn($q) => $q['anzahl'], $quellen)); ?>
              <div class="rangliste">
                <?php foreach ($quellen as $q): ?>
                  <div class="rang">
                    <span class="rang__name"><?= Util::h($q['name']) ?></span>
                    <span class="rang__wert"><?= Util::zahl($q['anzahl']) ?></span>
                    <span class="rang__spur"><span class="rang__fuellung"
                          data-breite="<?= (int) round($q['anzahl'] / max(1, $max) * 100) ?>%"></span></span>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </div>
          <div>
            <div class="versal gedimmt-2 mb-3">Beliebteste Seiten</div>
            <?php if ($topSeiten === []): ?>
              <p class="klein gedimmt">Keine Daten.</p>
            <?php else:
              $max = max(array_map(static fn($s) => $s['anzahl'], $topSeiten)); ?>
              <div class="rangliste">
                <?php foreach ($topSeiten as $s): ?>
                  <div class="rang">
                    <span class="rang__name"><?= Util::h(Util::kuerzen($s['name'], 34)) ?></span>
                    <span class="rang__wert"><?= Util::zahl($s['anzahl']) ?></span>
                    <span class="rang__spur"><span class="rang__fuellung"
                          data-breite="<?= (int) round($s['anzahl'] / max(1, $max) * 100) ?>%"></span></span>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </div>
        </div>
      </div>
      <div class="karte__fuss">
        <?= Icon::svg('shield', 15) ?>
        <span>Gezählt wird ohne Cookies. Aus IP und Browserkennung entsteht ein täglich
          wechselnder Prüfwert – eine Rückverfolgung auf eine Person ist damit nicht möglich.</span>
      </div>
    </div>
  </div>

  <div class="stapel">
    <?php if (Auth::darf('ai.use') && Tenant::modul('ai')): ?>
    <div class="ki-block">
      <div class="ki-block__kopf"><?= Icon::svg('ai', 15) ?> Was sagen die Zahlen?</div>
      <div class="ki-block__text"><?= Util::einfachesMarkdown(KI::diagrammErklaeren('umsatz', $k)) ?></div>
    </div>
    <?php endif; ?>

    <div class="karte">
      <div class="karte__kopf"><h3>Anfragen</h3></div>
      <div class="karte__koerper karte__koerper--eng">
        <div class="stapel stapel--eng klein">
          <?php
          $trichter = Leads::trichter(90);
          foreach ([
            ['Anfragen (90 Tage)', Util::zahl($trichter['gesamt'])],
            ['Daraus Kunden', Util::zahl($trichter['stufen']['kunde'] ?? 0)],
            ['Abschlussquote', Util::prozent($trichter['quote'], 1)],
            ['Offener Wert', Util::geld($trichter['wert'])],
            ['Besucher → Anfrage', Analytics::formatieren($k['conversion'])],
          ] as [$label, $wert]): ?>
            <div class="reihe"><span class="gedimmt"><?= Util::h($label) ?></span>
              <div class="fueller"></div><span class="halbfett"><?= Util::h($wert) ?></span></div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

    <?php if ($preisempfehlungen !== []): ?>
    <div class="karte">
      <div class="karte__kopf"><h3>Preisempfehlungen</h3>
        <div class="fueller"></div><span class="klein gedimmt">90 Tage Auslastung</span></div>
      <div class="karte__koerper karte__koerper--eng">
        <div class="stapel stapel--eng">
          <?php foreach ($preisempfehlungen as $p): ?>
            <div class="empfehlung">
              <div class="empfehlung__symbol empfehlung__symbol--<?= $p['richtung'] === 'hoch' ? 'akzent' : 'info' ?>">
                <?= Icon::svg($p['richtung'] === 'hoch' ? 'trend-up' : 'trend-down', 16) ?>
              </div>
              <div class="empfehlung__text">
                <div class="empfehlung__titel"><?= Util::h($p['titel']) ?></div>
                <div class="empfehlung__grund"><?= Util::h($p['text']) ?></div>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
      <div class="karte__fuss">
        <?= Icon::svg('info', 15) ?>
        <span>Vorschläge aus der tatsächlichen Auslastung. Preise ändert nur ein Mensch.</span>
      </div>
    </div>
    <?php endif; ?>

    <div class="karte">
      <div class="karte__kopf"><h3>Export</h3></div>
      <div class="karte__koerper karte__koerper--eng">
        <div class="stapel stapel--eng">
          <a class="btn btn--voll" href="<?= Util::attr(App::url('/app/rechnungen.php?aktion=csv&von='
                . $z['von'] . '&bis=' . $z['bis'])) ?>">
            <?= Icon::svg('download', 15) ?> Buchungssätze (CSV)</a>
          <a class="btn btn--voll" href="<?= Util::attr(App::url('/app/kunden.php?aktion=export')) ?>">
            <?= Icon::svg('download', 15) ?> Kundenliste (CSV)</a>
        </div>
      </div>
    </div>
  </div>
</div>

<?php require __DIR__ . '/partials/fuss.php'; ?>
