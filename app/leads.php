<?php
/**
 * Lead-Pipeline als Tafel.
 *
 * Karten ziehen statt Formulare ausfüllen: Eine Anfrage wandert mit der
 * Maus von „Neu“ nach „Kontaktiert“. Der Server bekommt die Änderung per
 * fetch; scheitert sie, springt die Karte zurück – eine stille
 * Falschanzeige wäre schlimmer als ein sichtbarer Fehler.
 */
require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/partials/helfer.php';

Auth::fordern('modul.leads');

/* Ziehen und Ablegen meldet sich hier per fetch. */
if (App::istPost() && App::get('json') === '1') {
    if (!Auth::csrfPruefen() || !Auth::darf('leads.write')) {
        App::json(['ok' => false, 'fehler' => 'Keine Berechtigung.'], 403);
    }
    $ok = Leads::stufeSetzen(App::postInt('id'), App::post('ziel'));
    App::json(['ok' => $ok, 'meldung' => $ok ? 'Stufe geändert.' : 'Stufe unbekannt.']);
}

if (App::istPost()) {
    Auth::csrfFordern();
    Auth::fordern('leads.write');
    if (App::aktion() === 'neu') {
        Leads::speichern([
            'name' => App::post('name'), 'email' => App::post('email'),
            'telefon' => App::post('telefon'), 'quelle' => App::post('quelle', 'manuell'),
            'interesse' => App::post('interesse'), 'wert_cent' => Util::centAus(App::post('wert')),
            'notiz' => App::post('notiz'), 'user_id' => Auth::id(),
        ]);
        App::melden('Anfrage erfasst.');
    }
    App::weiter('/app/leads.php');
}

$suche  = App::get('q');
$quelle = App::get('quelle');
$pipeline = Leads::pipeline(['suche' => $suche, 'quelle' => $quelle]);
$trichter = Leads::trichter(90);
$unbeantwortet = count(Leads::unbeantwortet(24));

$titel = 'Leads';
$unter = 'Offener Wert in der Pipeline: ' . Util::geld($trichter['wert'])
       . ' · Abschlussquote 90 Tage: ' . Util::prozent($trichter['quote'], 1);
$aktionen = Auth::darf('leads.write')
    ? '<button class="btn btn--primaer" data-modal-auf="modal-lead">' . Icon::svg('plus', 15) . ' Anfrage erfassen</button>' : '';
$inhaltKlasse = 'inhalt--breit';
require __DIR__ . '/partials/kopf.php';
?>

<?php if ($unbeantwortet > 0): ?>
  <div class="hinweis hinweis--gefahr mb-4">
    <?= Icon::svg('alert', 17) ?>
    <div class="hinweis__text">
      <span class="hinweis__titel"><?= $unbeantwortet ?> Anfrage<?= $unbeantwortet === 1 ? '' : 'n' ?>
        warten länger als 24 Stunden auf Antwort</span>
      Wer innerhalb eines Tages antwortet, gewinnt deutlich häufiger den Auftrag. Die betroffenen
      Karten stehen ganz links.
    </div>
  </div>
<?php endif; ?>

<div class="reihe reihe--umbruch mb-4">
  <form method="get" class="reihe reihe--eng">
    <div class="such-feld" style="min-width:220px">
      <?= Icon::svg('search', 16) ?>
      <input class="eingabe" name="q" value="<?= Util::attr($suche) ?>" data-such-absenden
             placeholder="Name oder Interesse" aria-label="Anfragen durchsuchen">
    </div>
    <select name="quelle" data-auto-absenden aria-label="Quelle">
      <option value="">Alle Quellen</option>
      <?php foreach (Leads::quellen(365) as $q): ?>
        <option value="<?= Util::attr($q['quelle']) ?>"<?= $quelle === $q['quelle'] ? ' selected' : '' ?>>
          <?= Util::h($q['quelle']) ?> (<?= $q['anzahl'] ?>)</option>
      <?php endforeach; ?>
    </select>
  </form>
  <div class="fueller"></div>
  <span class="klein gedimmt nicht-mobil">Karten lassen sich zwischen den Spalten ziehen.</span>
</div>

<div class="kanban">
  <?php foreach (Leads::STUFEN as $stufe => [$name, $farbe]):
    $karten = $pipeline[$stufe] ?? [];
    $summe = 0;
    foreach ($karten as $k) { $summe += (int) $k['wert_cent']; } ?>
    <div class="spalte" data-ablegen="<?= Util::attr(App::url('/app/leads.php?json=1')) ?>"
         data-ablegen-wert="<?= Util::attr($stufe) ?>">
      <div class="spalte__kopf">
        <span class="spalte__punkt" style="background:<?= Util::attr($farbe) ?>"></span>
        <span class="spalte__name"><?= Util::h($name) ?></span>
        <span class="spalte__zahl"><?= count($karten) ?></span>
        <?php if ($summe > 0): ?>
          <span class="spalte__summe"><?= Util::h(Util::geldKurz($summe)) ?></span>
        <?php endif; ?>
      </div>
      <div class="spalte__koerper" data-ablage-koerper>
        <?php foreach ($karten as $l):
          $wartet = (string) $l['stufe'] === 'neu' && !$l['letzter_kontakt']
                  && Util::tageSeit((string) $l['erstellt']) >= 1; ?>
          <a class="kanban-karte" draggable="true" data-ziehbar data-id="<?= (int) $l['id'] ?>"
             href="<?= Util::attr(App::url('/app/lead.php?id=' . (int) $l['id'])) ?>">
            <div class="kanban-karte__titel"><?= Util::h((string) $l['name']) ?></div>
            <?php if ((string) $l['interesse'] !== ''): ?>
              <div class="kanban-karte__unter"><?= Util::h((string) $l['interesse']) ?></div>
            <?php endif; ?>
            <div class="kanban-karte__fuss">
              <?php if ((int) $l['wert_cent'] > 0): ?>
                <span class="halbfett"><?= Util::h(Util::geldKurz((int) $l['wert_cent'])) ?></span>
              <?php endif; ?>
              <div class="fueller"></div>
              <?php if ($wartet): ?>
                <span class="pille pille--gefahr"><?= Util::h(Util::relativ((string) $l['erstellt'])) ?></span>
              <?php else: ?>
                <span class="gedimmt-2"><?= Util::h(Util::relativ((string) $l['erstellt'])) ?></span>
              <?php endif; ?>
            </div>
            <?php if ((string) $l['quelle'] !== ''): ?>
              <div class="mt-2"><?= pille((string) $l['quelle'], 'offen') ?></div>
            <?php endif; ?>
          </a>
        <?php endforeach; ?>
        <?php if ($karten === []): ?>
          <div class="klein gedimmt-2 mitte" style="padding:var(--r4) var(--r2)">
            <?= $stufe === 'neu' ? 'Neue Anfragen von der Website landen hier.' : '—' ?>
          </div>
        <?php endif; ?>
      </div>
    </div>
  <?php endforeach; ?>
</div>

<div class="raster raster--2 mt-6">
  <div class="karte">
    <div class="karte__kopf"><h3>Woher die Anfragen kommen</h3>
      <div class="fueller"></div><span class="klein gedimmt">letzte 180 Tage</span></div>
    <div class="karte__koerper">
      <?php $quellen = Leads::quellen(180);
      if ($quellen === []): ?>
        <p class="klein gedimmt mitte">Noch keine Anfragen erfasst.</p>
      <?php else:
        $max = max(array_map(static fn($q) => $q['anzahl'], $quellen)); ?>
        <div class="rangliste">
          <?php foreach ($quellen as $q): ?>
            <div class="rang">
              <span class="rang__name"><?= Util::h($q['quelle']) ?></span>
              <span class="rang__wert"><?= $q['anzahl'] ?>
                <span class="gedimmt klein">→ <?= $q['kunden'] ?> Kunden</span></span>
              <span class="rang__spur"><span class="rang__fuellung"
                    data-breite="<?= (int) round($q['anzahl'] / max(1, $max) * 100) ?>%"></span></span>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <div class="karte">
    <div class="karte__kopf"><h3>Trichter</h3>
      <div class="fueller"></div><span class="klein gedimmt">letzte 90 Tage</span></div>
    <div class="karte__koerper">
      <?php $max = max(1, max($trichter['stufen'])); ?>
      <div class="rangliste">
        <?php foreach (Leads::STUFEN as $stufe => [$name, $farbe]):
          $n = $trichter['stufen'][$stufe] ?? 0; ?>
          <div class="rang">
            <span class="rang__name"><?= Util::h($name) ?></span>
            <span class="rang__wert"><?= $n ?></span>
            <span class="rang__spur"><span class="rang__fuellung"
                  style="background:<?= Util::attr($farbe) ?>"
                  data-breite="<?= (int) round($n / $max * 100) ?>%"></span></span>
          </div>
        <?php endforeach; ?>
      </div>
      <div class="hinweis hinweis--still mt-4">
        <?= Icon::svg('info', 16) ?>
        <div class="hinweis__text">Von <?= $trichter['gesamt'] ?> Anfragen sind
          <?= $trichter['stufen']['kunde'] ?? 0 ?> Kunden geworden –
          <?= Util::prozent($trichter['quote'], 1) ?>.</div>
      </div>
    </div>
  </div>
</div>

<?php if (Auth::darf('leads.write')): ?>
<dialog class="modal" id="modal-lead">
  <form method="post">
    <?= Auth::csrfFeld() ?>
    <input type="hidden" name="aktion" value="neu">
    <div class="modal__kopf"><h2>Anfrage erfassen</h2>
      <button type="button" class="rundknopf" data-modal-zu aria-label="Schließen"><?= Icon::svg('x', 17) ?></button></div>
    <div class="modal__koerper">
      <div class="feld"><label class="feld__label" for="ld-name">Name</label>
        <input class="eingabe" id="ld-name" name="name" required autofocus></div>
      <div class="feld-reihe feld-reihe--2">
        <div class="feld"><label class="feld__label" for="ld-email">E-Mail</label>
          <input class="eingabe" id="ld-email" type="email" name="email"></div>
        <div class="feld"><label class="feld__label" for="ld-telefon">Telefon</label>
          <input class="eingabe" id="ld-telefon" type="tel" name="telefon"></div>
      </div>
      <div class="feld-reihe feld-reihe--2">
        <div class="feld"><label class="feld__label" for="ld-interesse">Interesse</label>
          <input class="eingabe" id="ld-interesse" name="interesse" placeholder="Platzreife"></div>
        <div class="feld"><label class="feld__label" for="ld-quelle">Quelle</label>
          <input class="eingabe" id="ld-quelle" name="quelle" list="quellenliste" value="Telefon">
          <datalist id="quellenliste">
            <option>Website</option><option>Telefon</option><option>Empfehlung</option>
            <option>Google</option><option>Instagram</option><option>Club</option>
          </datalist></div>
      </div>
      <div class="feld"><label class="feld__label" for="ld-wert">Erwarteter Wert</label>
        <div class="eingabe-gruppe">
          <input class="eingabe" id="ld-wert" name="wert" placeholder="349,00">
          <span class="eingabe-gruppe__anhang"><?= Util::h(Tenant::waehrung()) ?></span>
        </div></div>
      <div class="feld"><label class="feld__label" for="ld-notiz">Notiz</label>
        <textarea class="eingabe" id="ld-notiz" name="notiz" rows="3" data-waechst></textarea></div>
    </div>
    <div class="modal__fuss"><div class="fueller"></div>
      <button type="button" class="btn" data-modal-zu>Abbrechen</button>
      <button type="submit" class="btn btn--primaer">Erfassen</button></div>
  </form>
</dialog>
<?php endif; ?>

<?php require __DIR__ . '/partials/fuss.php'; ?>
