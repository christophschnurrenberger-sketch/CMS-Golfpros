<?php
/**
 * Tarif und Funktionsumfang.
 *
 * Hier steckt das Leitprinzip „einfach im Standard, mächtig bei Bedarf“:
 * Ein Tarif legt fest, was verfügbar *wäre*; die Schalter darunter
 * bestimmen, was tatsächlich im Menü erscheint. Wer nur unterrichtet,
 * lässt Automationen und Community aus und sieht neun Menüpunkte statt 22.
 */
require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/partials/helfer.php';

Auth::fordern('modul.settings');
Auth::fordern('settings.allgemein');

$plaene = Module::plaene();

if (App::istPost()) {
    Auth::csrfFordern();
    $aktion = App::aktion();

    if ($aktion === 'plan' && Auth::istInhaber()) {
        $neu = App::post('plan');
        if (isset($plaene[$neu])) {
            $alt = Tenant::plan();
            Tenant::aktualisieren(['plan' => $neu]);
            /*
             * Beim Wechsel nach unten werden Module abgeschaltet, die der
             * neue Tarif nicht mehr enthält – die Daten bleiben aber
             * vollständig erhalten. Ein Wechsel zurück macht alles sichtbar.
             */
            $module = Tenant::einstellung('module', null);
            if (is_array($module)) {
                $gefiltert = array_values(array_filter(
                    $module,
                    static fn ($k) => Module::istKern((string) $k) || Module::imPlan((string) $k, $neu)
                ));
                Tenant::einstellungSetzen('module', $gefiltert);
            }
            Audit::schreiben('geaendert', 'workspace', Tenant::id(), 'Tarif ' . $alt . ' → ' . $neu);
            App::melden('Tarif auf ' . Module::planName($neu) . ' gestellt.');
        }
    }

    if ($aktion === 'module') {
        $gewaehlt = App::postListe('module');
        $an = Module::KERN;
        foreach (Module::alle() as $key => $info) {
            if (in_array($key, $gewaehlt, true) && Module::imPlan($key, Tenant::plan())) {
                $an[] = $key;
            }
        }
        Tenant::einstellungSetzen('module', array_values(array_unique($an)));
        App::melden('Funktionsumfang gespeichert.');
    }

    App::weiter('/app/tarif.php');
}

$plan     = Tenant::plan();
$rang     = ['starter' => 1, 'pro' => 2, 'business' => 3, 'academy' => 4];
$module   = Module::alle();
$anzahlAn = 0;
foreach ($module as $key => $info) {
    if (Tenant::modul($key)) {
        $anzahlAn++;
    }
}

$titel = 'Tarif und Funktionsumfang';
$unter = 'Was der Tarif enthält – und was davon du wirklich sehen willst.';
$brotkrumen = [['Einstellungen', '/app/einstellungen.php'], ['Tarif', null]];
require __DIR__ . '/partials/kopf.php';
?>

<div class="raster raster--4 mb-5">
  <?php foreach ($plaene as $key => $p):
    $istAktuell = $key === $plan;
    $istHoeher  = ($rang[$key] ?? 0) > ($rang[$plan] ?? 0); ?>
    <div class="karte tarifkarte<?= $istAktuell ? ' karte--heraus' : '' ?>">
      <div class="karte__koerper">
        <div class="reihe reihe--zwischen mb-2">
          <h3><?= Util::h((string) $p['name']) ?></h3>
          <?php if ($istAktuell): ?><?= pille('aktuell', 'marke') ?><?php endif; ?>
        </div>
        <div class="kennzahl__wert" style="margin-bottom:4px">
          <?= Util::h(Util::geldKurz((int) $p['preis_cent'])) ?>
          <small class="gedimmt">/ Monat</small>
        </div>
        <p class="klein gedimmt" style="min-height:3.2em"><?= Util::h((string) $p['zeile']) ?></p>
        <ul class="stapel stapel--eng mt-3" style="list-style:none;padding:0">
          <?php foreach ((array) $p['enthalten'] as $zeile): ?>
            <li class="reihe reihe--eng klein">
              <span style="color:var(--marke);display:flex"><?= Icon::svg('check', 14) ?></span>
              <span><?= Util::h((string) $zeile) ?></span>
            </li>
          <?php endforeach; ?>
        </ul>
      </div>
      <?php if (Auth::istInhaber() && !$istAktuell): ?>
        <div class="karte__fuss">
          <form method="post" style="width:100%"
                data-bestaetigen="<?= $istHoeher
                    ? 'Auf ' . Util::attr((string) $p['name']) . ' wechseln?'
                    : 'Beim Wechsel nach unten verschwinden Bereiche aus dem Menü. Die Daten bleiben erhalten. Fortfahren?' ?>">
            <?= Auth::csrfFeld() ?>
            <input type="hidden" name="aktion" value="plan">
            <input type="hidden" name="plan" value="<?= Util::attr($key) ?>">
            <button class="btn btn--voll<?= $istHoeher ? ' btn--primaer' : '' ?>" type="submit">
              <?= $istHoeher ? 'Auf ' . Util::h((string) $p['name']) . ' wechseln' : 'Zu ' . Util::h((string) $p['name']) . ' zurück' ?>
            </button>
          </form>
        </div>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
</div>

<?php if (!Auth::istInhaber()): ?>
  <div class="hinweis hinweis--still mb-4">
    <?= Icon::svg('lock', 17) ?>
    <div class="hinweis__text">Den Tarif kann nur der Inhaber des Workspace wechseln.</div>
  </div>
<?php endif; ?>

<form method="post">
  <?= Auth::csrfFeld() ?>
  <input type="hidden" name="aktion" value="module">
  <?= karteAuf('Was im Menü erscheint',
        '<span class="klein gedimmt">' . $anzahlAn . ' von ' . count($module) . ' Bereichen sichtbar</span>') ?>
    <div class="karte__koerper">
      <p class="gedimmt mb-4">Jeder ausgeschaltete Bereich verschwindet aus dem Menü –
        die Daten dahinter bleiben unberührt und sind nach dem Wiedereinschalten sofort wieder da.
        Sechs Bereiche sind fest eingebaut, weil ohne sie nichts funktioniert.</p>

      <?php foreach (['kunden', 'training', 'verkauf', 'web', 'wachstum', 'wissen'] as $gruppe):
        $inGruppe = array_filter($module, static fn ($i) => ($i['gruppe'] ?? '') === $gruppe);
        if ($inGruppe === []) { continue; } ?>
        <div class="mb-5">
          <div class="versal gedimmt mb-3"><?= Util::h(Module::gruppenname($gruppe)) ?></div>
          <div class="raster raster--2">
            <?php foreach ($inGruppe as $key => $info):
              $kern     = Module::istKern($key);
              $imPlan   = Module::imPlan($key, $plan);
              $an       = Tenant::modul($key); ?>
              <label class="haken<?= !$imPlan ? ' nur-lesbar' : '' ?>">
                <input type="checkbox" name="module[]" value="<?= Util::attr($key) ?>"
                       <?= $an ? ' checked' : '' ?>
                       <?= $kern || !$imPlan ? ' disabled' : '' ?>>
                <span class="haken__text">
                  <?= Util::h((string) $info['name']) ?>
                  <?php if ($kern): ?> <?= pille('Kern') ?>
                  <?php elseif (!$imPlan): ?> <?= pille('ab ' . Module::planName((string) $info['plan']), 'warnung') ?>
                  <?php endif; ?>
                  <span class="haken__hinweis"><?= Util::h((string) $info['beschreibung']) ?></span>
                </span>
              </label>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
    <div class="karte__fuss">
      <span class="klein gedimmt">Kernbereiche lassen sich nicht abschalten.</span>
      <div class="fueller"></div>
      <button class="btn btn--primaer" type="submit">Speichern</button>
    </div>
  </div>
</form>

<?php require __DIR__ . '/partials/fuss.php'; ?>
