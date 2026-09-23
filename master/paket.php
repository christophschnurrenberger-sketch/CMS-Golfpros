<?php
/**
 * Ein Paket anlegen oder bearbeiten.
 */
require __DIR__ . '/partials/start.php';
Betreiber::fordern('pakete.write');

$id = App::getInt('id', App::postInt('id'));
$paket = $id > 0 ? Pakete::nachId($id) : null;
if ($id > 0 && $paket === null) {
    App::melden('Dieses Paket gibt es nicht.', 'fehler');
    App::weiter('/master/pakete.php');
}
$fehler = [];
$e = $paket ?? ['name' => '', 'beschreibung' => '', 'preis_monat_cent' => 0, 'preis_jahr_cent' => 0,
                'module' => [], 'enthalten' => [], 'team_grenze' => 1, 'aktiv' => 1, 'sortierung' => 100];

if (App::istPost()) {
    Auth::csrfFordern();
    $eingabe = [
        'name' => App::post('name'), 'beschreibung' => App::post('beschreibung'),
        'preis_monat_cent' => Util::centAus(App::post('preis_monat')), 'preis_jahr_cent' => Util::centAus(App::post('preis_jahr')),
        'module' => App::postListe('module'), 'enthalten' => App::postRoh('enthalten'),
        'team_grenze' => App::postInt('team_grenze'), 'aktiv' => App::postBool('aktiv'), 'sortierung' => App::postInt('sortierung', 100),
    ];
    [$neu, $fehler] = Pakete::speichern($eingabe, $id);
    if ($fehler === []) {
        App::melden('Paket gespeichert.');
        App::weiter('/master/paket.php?id=' . $neu);
    }
    $e = ['name' => $eingabe['name'], 'beschreibung' => $eingabe['beschreibung'], 'preis_monat_cent' => $eingabe['preis_monat_cent'],
          'preis_jahr_cent' => $eingabe['preis_jahr_cent'], 'module' => $eingabe['module'],
          'enthalten' => preg_split('/\r?\n/', $eingabe['enthalten']) ?: [], 'team_grenze' => $eingabe['team_grenze'],
          'aktiv' => $eingabe['aktiv'] ? 1 : 0, 'sortierung' => $eingabe['sortierung']] + ($paket ?? []);
}

$titel = $paket !== null ? 'Paket: ' . $paket['name'] : 'Neues Paket';
$unter = $paket !== null ? Pakete::inBenutzung((string) $paket['schluessel']) . ' Instanzen auf diesem Paket · Schlüssel <span class="mono">'
       . Util::h((string) $paket['schluessel']) . '</span>' : 'Der interne Schlüssel wird vergeben, nicht aus dem Namen gebildet.';
$bereich = 'pakete';
$brotkrumen = [['Übersicht', '/master/'], ['Pakete', '/master/pakete.php'], [$paket !== null ? (string) $paket['name'] : 'Neu', null]];
require __DIR__ . '/partials/kopf.php';
$geld = static fn (int $c): string => $c > 0 ? number_format($c / 100, 2, ',', '') : '';
?>

<?php if ($fehler !== []): ?>
  <div class="hinweis hinweis--gefahr mb-4"><?= Icon::svg('alert', 17) ?><div class="hinweis__text"><?= Util::h(implode(' ', $fehler)) ?></div></div>
<?php endif; ?>

<form method="post" action="<?= Util::attr(App::url('/master/paket.php')) ?>" class="raster raster--haupt-neben">
  <?= Auth::csrfFeld() ?>
  <input type="hidden" name="id" value="<?= (int) $id ?>">
  <div class="stapel">
    <?= karteAuf('Angaben') ?>
      <div class="karte__koerper">
        <div class="feld"><label class="feld__label" for="p-name">Name</label>
          <input class="eingabe" id="p-name" name="name" required maxlength="80" value="<?= Util::attr((string) $e['name']) ?>"></div>
        <div class="feld"><label class="feld__label" for="p-beschreibung">Beschreibung</label>
          <input class="eingabe" id="p-beschreibung" name="beschreibung" maxlength="500" value="<?= Util::attr((string) $e['beschreibung']) ?>"></div>
        <div class="feld-reihe feld-reihe--3">
          <div class="feld"><label class="feld__label" for="p-monat">Preis je Monat</label>
            <input class="eingabe" id="p-monat" name="preis_monat" inputmode="decimal" placeholder="0,00" value="<?= Util::attr($geld((int) $e['preis_monat_cent'])) ?>"></div>
          <div class="feld"><label class="feld__label" for="p-jahr">Preis je Jahr</label>
            <input class="eingabe" id="p-jahr" name="preis_jahr" inputmode="decimal" placeholder="leer = keiner" value="<?= Util::attr($geld((int) $e['preis_jahr_cent'])) ?>"></div>
          <div class="feld"><label class="feld__label" for="p-team">Team-Grenze</label>
            <input class="eingabe" id="p-team" name="team_grenze" type="number" min="0" max="999" value="<?= (int) $e['team_grenze'] ?>">
            <div class="feld__hinweis">0 = unbegrenzt. Wird in „Team" durchgesetzt.</div></div>
        </div>
        <div class="feld"><label class="feld__label" for="p-enthalten">Stichpunkte für die Tarifseite (einer je Zeile)</label>
          <textarea class="eingabe" id="p-enthalten" name="enthalten" rows="5"><?= Util::h(implode("\n", (array) $e['enthalten'])) ?></textarea></div>
        <div class="feld-reihe feld-reihe--2">
          <div class="feld"><label class="feld__label" for="p-sort">Reihenfolge</label>
            <input class="eingabe" id="p-sort" name="sortierung" type="number" value="<?= (int) $e['sortierung'] ?>"></div>
          <div class="feld"><span class="feld__label">Angebot</span>
            <label class="haken"><input type="checkbox" name="aktiv" value="1"<?= (int) $e['aktiv'] === 1 ? ' checked' : '' ?>>
              <span class="haken__text">Wird angeboten<span class="haken__hinweis">Aus: Bestehende Instanzen behalten es, neue bekommen es nicht.</span></span></label></div>
        </div>
      </div>
    </div>
  </div>

  <?= karteAuf('Module im Paket') ?>
    <div class="karte__koerper">
      <p class="klein gedimmt">Kernbereiche sind immer enthalten. Was hier fehlt, ist in Instanzen auf diesem Paket gesperrt –
        im Menü und über die Adresszeile.</p>
      <?php foreach (Module::alle() as $key => $info): ?>
        <label class="haken haken--eng">
          <input type="checkbox" name="module[]" value="<?= Util::attr($key) ?>"
                 <?= Module::istKern($key) ? 'checked disabled' : (in_array($key, (array) $e['module'], true) ? 'checked' : '') ?>>
          <span class="haken__text"><?= Util::h((string) $info['name']) ?><?= Module::istKern($key) ? ' <span class="winzig gedimmt-2">Kern</span>' : '' ?>
            <span class="haken__hinweis"><?= Util::h((string) $info['beschreibung']) ?></span></span></label>
      <?php endforeach; ?>
      <div class="mt-4 reihe">
        <button class="btn btn--primaer" type="submit"<?= $paket !== null && Pakete::inBenutzung((string) $paket['schluessel']) > 0
            ? ' data-bestaetigen="Die Änderung gilt sofort für ' . Pakete::inBenutzung((string) $paket['schluessel']) . ' Instanzen auf diesem Paket. Speichern?"' : '' ?>>Speichern</button>
        <a class="btn btn--geist" href="<?= Util::attr(App::url('/master/pakete.php')) ?>">Abbrechen</a>
      </div>
    </div>
  </div>
</form>

<?php require __DIR__ . '/partials/fuss.php';
