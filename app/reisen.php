<?php
/** Golfreisen – Übersicht über alle Reisen und ihre Auslastung. */
require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/partials/helfer.php';

Auth::fordern('modul.travel');

if (App::istPost()) {
    Auth::csrfFordern();
    Auth::fordern('travel.write');

    if (App::aktion() === 'neu') {
        $start = App::post('start');
        $ende  = App::post('ende') ?: date('Y-m-d', strtotime($start . ' +7 days'));
        $id = Trips::speichern([
            'titel'      => App::post('titel'),
            'ziel'       => App::post('ziel'),
            'land'       => App::post('land'),
            'start'      => $start . ' 00:00:00',
            'ende'       => $ende . ' 00:00:00',
            'plaetze'    => max(1, App::postInt('plaetze', 12)),
            'preis_cent' => Util::centAus(App::post('preis')),
            'anreise'    => array_key_exists(App::post('anreise'), Trips::ANREISE) ? App::post('anreise') : 'eigen',
            'trainer_id' => Auth::id(),
            'status'     => 'geplant',
        ]);
        App::melden('Reise angelegt. Jetzt die Einzelheiten ergänzen.');
        App::weiter('/app/reise.php?id=' . $id);
    }
}

$zeit = App::get('zeit', 'kommend');
$wo   = $zeit === 'vergangen' ? 'ende < :jetzt' : 'ende >= :jetzt';
$reisen = Tenant::all('trips', $wo, ['jetzt' => Util::jetzt()],
    $zeit === 'vergangen' ? 'start DESC' : 'start');

$offeneAnmeldungen = Tenant::count('trip_signups', "status IN ('angemeldet', 'bestaetigt')");
$gebunden = 0;
foreach (Trips::kommende(50, false) as $r) {
    $gebunden += Trips::umsatz((int) $r['id']);
}

$titel = 'Reisen';
$unter = Tenant::count('trips', 'ende >= :jetzt', ['jetzt' => Util::jetzt()]) . ' kommende Reisen · '
       . $offeneAnmeldungen . ' Anmeldungen · ' . Util::geld($gebunden) . ' gebucht';
$aktionen = '<a class="btn" target="_blank" rel="noopener" href="' . Util::attr(Oeffentlich::url('/reisen.php')) . '">'
          . Icon::svg('external', 15) . ' Reisekatalog</a>'
          . (Auth::darf('travel.write')
             ? '<button class="btn btn--primaer" data-modal-auf="modal-reise">' . Icon::svg('plus', 15) . ' Reise anlegen</button>' : '');
require __DIR__ . '/partials/kopf.php';
?>

<div class="reiter">
  <a class="reiter__teil<?= $zeit === 'kommend' ? ' ist-aktiv' : '' ?>"
     href="<?= Util::attr(App::url('/app/reisen.php')) ?>">Kommende</a>
  <a class="reiter__teil<?= $zeit === 'vergangen' ? ' ist-aktiv' : '' ?>"
     href="<?= Util::attr(App::url('/app/reisen.php?zeit=vergangen')) ?>">Vergangene</a>
</div>

<?php if ($reisen === []): ?>
  <div class="karte"><div class="karte__koerper">
    <?= leerzustand('globe', 'Keine Reisen in dieser Auswahl',
          'Eine Woche Mallorca mit zwölf Teilnehmern ist der größte Einzelposten, den eine '
          . 'Golfschule verkaufen kann – und die Teilnehmer kommen als Gruppe zurück. '
          . 'Zimmerwahl, Anzahlung, Mindestteilnehmer und Warteliste sind eingebaut.') ?>
  </div></div>
<?php else: ?>
  <div class="raster raster--auto-gross">
    <?php foreach ($reisen as $r):
      $belegt   = Trips::belegt((int) $r['id']);
      $frei     = Trips::freiePlaetze($r);
      $warte    = array_sum(array_map(static fn ($b) => (int) $b['personen'], Trips::anmeldungen((int) $r['id'], 'warteliste')));
      $fehlt    = Trips::fehlend($r);
      $quote    = (int) $r['plaetze'] > 0 ? (int) round($belegt / (int) $r['plaetze'] * 100) : 0;
      $bilder   = count(Trips::galerie($r)) + ((string) $r['bild'] !== '' ? 1 : 0); ?>
      <a class="karte reisekarte" href="<?= Util::attr(App::url('/app/reise.php?id=' . (int) $r['id'])) ?>">
        <span class="reisekarte__bild">
          <?php if ((string) $r['bild'] !== ''): ?>
            <img src="<?= Util::attr(App::url((string) $r['bild'])) ?>" alt="" loading="lazy">
          <?php else: ?>
            <span class="reisekarte__ohne"><?= Icon::svg('image', 22) ?><span>Noch kein Titelbild</span></span>
          <?php endif; ?>
          <span class="reisekarte__marken">
            <?= pille(Trips::STATUS[(string) $r['status']] ?? (string) $r['status'], (string) $r['status'] === 'veroeffentlicht' ? 'erfolg' : '') ?>
            <?php if (Trips::istFruehbucher($r)): ?><?= pille('Frühbucher', 'marke') ?><?php endif; ?>
          </span>
          <?php if ($bilder > 1): ?><span class="reisekarte__zahl"><?= Icon::svg('image', 13) ?> <?= $bilder ?></span><?php endif; ?>
        </span>
        <div class="karte__koerper">
          <div class="reihe reihe--eng mb-2">
            <span class="klein gedimmt reihe reihe--eng"><?= Icon::svg('pin', 14) ?>
              <?= Util::h(trim((string) $r['ziel'] . ' · ' . (string) $r['land'], ' ·')) ?></span>
            <div class="fueller"></div>
            <span class="halbfett">ab <?= Util::h(Util::geldKurz(Trips::abPreis($r))) ?></span>
          </div>
          <h3 class="mb-2"><?= Util::h((string) $r['titel']) ?></h3>
          <div class="klein gedimmt reihe reihe--eng">
            <?= Icon::svg('calendar', 14) ?>
            <?= Util::h(Util::datum((string) $r['start'])) ?>–<?= Util::h(Util::datum((string) $r['ende'])) ?>
            · <?= (int) $r['naechte'] ?> Nächte · <?= Util::h(Trips::ANREISE[(string) $r['anreise']] ?? '') ?>
          </div>
          <div class="mt-4">
            <div class="reihe klein mb-2">
              <span class="gedimmt"><?= $belegt ?> von <?= (int) $r['plaetze'] ?> Plätzen</span>
              <div class="fueller"></div>
              <?php if ($fehlt > 0): ?>
                <span class="pille pille--warnung">noch <?= $fehlt ?> bis Mindestzahl</span>
              <?php elseif ($warte > 0): ?>
                <span class="pille pille--warnung"><?= $warte ?> auf Warteliste</span>
              <?php elseif ($frei === 0): ?>
                <?= pille('ausgebucht', 'gefahr') ?>
              <?php else: ?>
                <span class="gedimmt"><?= $frei ?> frei</span>
              <?php endif; ?>
            </div>
            <?= balken($quote, $quote >= 100 ? 'gefahr' : ($fehlt > 0 ? 'warnung' : '')) ?>
          </div>
        </div>
        <div class="karte__fuss">
          <span class="klein gedimmt">Gebucht <?= Util::h(Util::geldKurz(Trips::umsatz((int) $r['id']))) ?></span>
          <div class="fueller"></div>
          <?= Icon::svg('chevron-right', 15) ?>
        </div>
      </a>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<?php if (Auth::darf('travel.write')): ?>
<dialog class="modal" id="modal-reise">
  <form method="post">
    <?= Auth::csrfFeld() ?>
    <input type="hidden" name="aktion" value="neu">
    <div class="modal__kopf"><h2>Reise anlegen</h2>
      <button type="button" class="rundknopf" data-modal-zu aria-label="Schließen"><?= Icon::svg('x', 17) ?></button></div>
    <div class="modal__koerper">
      <?php /* Nur das Nötigste – alles Weitere auf der Reiseseite, wo Platz
               dafür ist. Ein Fenster mit zwanzig Feldern legt niemand an. */ ?>
      <div class="feld"><label class="feld__label" for="re-titel">Titel</label>
        <input class="eingabe" id="re-titel" name="titel" required autofocus
               placeholder="Golfwoche Mallorca"></div>
      <div class="feld-reihe feld-reihe--2">
        <div class="feld"><label class="feld__label" for="re-ziel">Ziel</label>
          <input class="eingabe" id="re-ziel" name="ziel" placeholder="Son Servera"></div>
        <div class="feld"><label class="feld__label" for="re-land">Land</label>
          <input class="eingabe" id="re-land" name="land" placeholder="Spanien"></div>
      </div>
      <div class="feld-reihe feld-reihe--2">
        <div class="feld"><label class="feld__label" for="re-start">Anreise</label>
          <input class="eingabe" id="re-start" type="date" name="start" required
                 value="<?= Util::attr(date('Y-m-d', strtotime('+90 days'))) ?>"></div>
        <div class="feld"><label class="feld__label" for="re-ende">Abreise</label>
          <input class="eingabe" id="re-ende" type="date" name="ende"
                 value="<?= Util::attr(date('Y-m-d', strtotime('+97 days'))) ?>"></div>
      </div>
      <div class="feld-reihe feld-reihe--3">
        <div class="feld"><label class="feld__label" for="re-plaetze">Plätze</label>
          <input class="eingabe" id="re-plaetze" type="number" name="plaetze" value="12" min="1"></div>
        <div class="feld"><label class="feld__label" for="re-preis">Preis im DZ</label>
          <div class="eingabe-gruppe">
            <input class="eingabe" id="re-preis" name="preis" value="1890,00">
            <span class="eingabe-gruppe__anhang"><?= Util::h(Tenant::waehrung()) ?></span></div></div>
        <div class="feld"><label class="feld__label" for="re-anreise">Anreise</label>
          <select id="re-anreise" name="anreise">
            <?php foreach (Trips::ANREISE as $k => $n): ?>
              <option value="<?= Util::attr($k) ?>"><?= Util::h($n) ?></option>
            <?php endforeach; ?>
          </select></div>
      </div>
    </div>
    <div class="modal__fuss"><div class="fueller"></div>
      <button type="button" class="btn" data-modal-zu>Abbrechen</button>
      <button type="submit" class="btn btn--primaer">Anlegen</button></div>
  </form>
</dialog>
<?php endif; ?>

<?php require __DIR__ . '/partials/fuss.php'; ?>
