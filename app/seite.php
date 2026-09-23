<?php
/**
 * Der Baukasten.
 *
 * Drei Spalten: links die Bausteine der Seite und der Vorrat, in der Mitte
 * die Seite selbst, rechts die Felder des gewählten Bausteins.
 *
 * Gerendert wird auf dem Server – dieselbe Funktion, die später die
 * öffentliche Seite ausgibt. Damit kann die Vorschau nicht von der
 * Wirklichkeit abweichen, und der Baukasten funktioniert auch dann, wenn
 * JavaScript einmal nicht lädt.
 */
require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/partials/helfer.php';

Auth::fordern('modul.website');

$istNeu = App::get('id') === 'neu';
$id     = $istNeu ? 0 : App::getInt('id');
$seite  = $istNeu ? null : Tenant::find('pages', $id);

if (!$istNeu && !$seite) {
    App::melden('Diese Seite wurde nicht gefunden.', 'fehler');
    App::weiter('/app/website.php');
}

/* Neue Seite sofort anlegen – ein leeres Formular wäre ein Umweg. */
if ($istNeu) {
    Auth::fordern('website.write');
    $art = App::get('art', 'seite');
    $vorlage = App::get('vorlage');

    $bloecke = match ($vorlage) {
        'leer'    => [],
        'ueber'   => [Bloecke::neu('hero'), Bloecke::neu('text'), Bloecke::neu('team'), Bloecke::neu('cta')],
        'preise'  => [Bloecke::neu('hero'), Bloecke::neu('leistungen'), Bloecke::neu('preise'),
                      Bloecke::neu('faq'), Bloecke::neu('cta')],
        'landing' => KI::landingpage(App::get('prompt', 'Golftraining'))['bloecke'],
        default   => [Bloecke::neu('hero'), Bloecke::neu('spalten'), Bloecke::neu('leistungen'),
                      Bloecke::neu('testimonials'), Bloecke::neu('buchung'), Bloecke::neu('cta')],
    };

    $neuId = Pages::speichern([
        'titel' => App::get('titel', $art === 'landingpage' ? 'Neue Landingpage' : 'Neue Seite'),
        'bloecke' => Util::json($bloecke),
        'art' => $art, 'status' => 'entwurf', 'im_menue' => $art === 'landingpage' ? 0 : 1,
    ]);

    /*
     * Gleich als Unterseite anlegen, wenn sie aus einer Zeile des Baums
     * heraus entstanden ist. Sonst legt man sie an, geht zurück in die
     * Übersicht und rückt sie von Hand ein – drei Schritte für etwas, das
     * beim Klicken schon feststand.
     */
    $eltern = App::getInt('eltern');
    if ($eltern > 0) {
        [$okay, $grund] = Pages::einordnen($neuId, $eltern);
        App::melden($okay
            ? 'Unterseite von „' . (Tenant::find('pages', $eltern)['titel'] ?? '') . '" angelegt.'
            : $grund, $okay ? 'erfolg' : 'warnung');
    }
    App::weiter('/app/seite.php?id=' . $neuId);
}

$bloecke  = array_values(Pages::bloecke($seite));
$gewaehlt = App::get('block');

/*
 * Ohne JavaScript arbeitet der Baukasten weiter über Formulare – dieselben
 * Handlungen wie app/bauen.php, dieselbe Stelle dahinter (Baukasten). Mit
 * JavaScript kommt hier nur noch an, was die Seite selbst betrifft.
 */
if (App::istPost()) {
    Auth::csrfFordern();
    Auth::fordern('website.write');
    $aktion  = App::aktion();
    $blockId = App::post('block_id');
    $zurueck = '/app/seite.php?id=' . $id;

    if ($aktion === 'block_hinzu') {
        [$neu, $grund] = Baukasten::hinzufuegen($id, App::post('typ'), App::post('nach'));
        if ($neu === null) {
            App::melden($grund, 'fehler');
            App::weiter($zurueck);
        }
        App::weiter($zurueck . '&block=' . rawurlencode((string) $neu['id']));
    }

    if ($aktion === 'block_speichern') {
        $i = Baukasten::stelle($bloecke, $blockId);
        if ($i !== null) {
            Baukasten::datenSetzen($id, $blockId, Baukasten::datenAusFormular((string) $bloecke[$i]['typ']));
            App::melden('Baustein gespeichert.');
        }
        App::weiter($zurueck . '&block=' . rawurlencode($blockId));
    }

    if ($aktion === 'block_weg') {
        Baukasten::entfernen($id, $blockId);
        App::melden('Baustein entfernt.');
        App::weiter($zurueck);
    }

    if ($aktion === 'block_kopie') {
        $kopie = Baukasten::kopieren($id, $blockId);
        App::weiter($zurueck . ($kopie !== null ? '&block=' . rawurlencode((string) $kopie['id']) : ''));
    }

    if ($aktion === 'block_schieben') {
        Baukasten::schieben($id, $blockId, App::post('richtung') === 'hoch' ? -1 : 1);
        App::weiter($zurueck . '&block=' . rawurlencode($blockId));
    }

    if ($aktion === 'seite_speichern') {
        /*
         * Das Einordnen läuft über Pages::einordnen() und nicht über das
         * Feld im Datensatz: Dort steckt der Schutz gegen Ringe und gegen
         * eine zu tiefe Verschachtelung. Ein „parent_id" aus dem Formular
         * kann jeden Wert haben, den jemand hineinschreibt.
         */
        if (isset($_POST['parent_id'])) {
            [$eingeordnet, $grund] = Pages::einordnen($id, App::postInt('parent_id'));
            if (!$eingeordnet) {
                App::melden($grund, 'warnung');
            }
        }
        $seo = Pages::seo($seite);
        Pages::speichern([
            'titel' => App::post('titel', (string) $seite['titel']), 'slug' => App::post('slug', (string) $seite['slug']),
            'status' => App::post('status', (string) $seite['status']),
            'im_menue' => isset($_POST['titel']) ? (App::postBool('im_menue') ? 1 : 0) : (int) $seite['im_menue'],
            'art' => App::post('art', (string) $seite['art']),
            'seo' => Util::json(isset($_POST['titel']) ? [
                'titel' => App::post('seo_titel'),
                'beschreibung' => App::post('seo_beschreibung'),
                'index' => App::postBool('seo_index'),
            ] : $seo),
        ], $id);
        App::melden(App::post('status') === 'veroeffentlicht' && (string) $seite['status'] !== 'veroeffentlicht'
            ? 'Die Seite ist veröffentlicht.' : 'Seite gespeichert.');
        App::weiter($zurueck . ($gewaehlt !== '' ? '&block=' . rawurlencode($gewaehlt) : ''));
    }

    if ($aktion === 'startseite') {
        Pages::startseiteSetzen($id);
        App::melden('Das ist jetzt die Startseite.');
        App::weiter($zurueck);
    }

    if ($aktion === 'loeschen') {
        if (Pages::loeschen($id)) {
            App::melden('Seite gelöscht.');
            App::weiter('/app/website.php');
        }
        App::melden('Die Startseite lässt sich nicht löschen. Mach zuerst eine andere Seite zur Startseite.', 'fehler');
        App::weiter($zurueck);
    }
    App::weiter($zurueck);
}

$gewaehlterBlock = null;
foreach ($bloecke as $b) {
    if ((string) ($b['id'] ?? '') === $gewaehlt) {
        $gewaehlterBlock = $b;
    }
}
$darfSchreiben = Auth::darf('website.write');
$istOeffentlich = (string) $seite['status'] === 'veroeffentlicht';

$titel = (string) $seite['titel'];
$ohneKopf = true;
$vollbild = true;
$inhaltKlasse = 'inhalt--rand-los';
$bereich = 'website';
require __DIR__ . '/partials/kopf.php';
?>

<div class="bau" data-bau data-seite="<?= $darfSchreiben ? (int) $id : '' ?>"
     data-gewaehlt="<?= Util::attr($gewaehlterBlock !== null ? $gewaehlt : '') ?>">

  <?php /* ================================================ Kopfleiste === */ ?>
  <header class="bau-kopf">
    <a class="rundknopf" href="<?= Util::attr(App::url('/app/website.php')) ?>"
       title="Zurück zur Website-Übersicht" aria-label="Zurück zur Website-Übersicht"><?= Icon::svg('arrow-left', 17) ?></a>
    <button type="button" class="rundknopf bau-kopf__aufbau" data-aufbau-auf data-nur-mit-js hidden
            title="Aufbau der Seite" aria-label="Aufbau der Seite zeigen"><?= Icon::svg('list', 17) ?></button>
    <div class="bau-kopf__seite">
      <a class="bau-kopf__titel" href="<?= Util::attr(App::url('/app/seite.php?id=' . $id)) ?>" data-bau-aktion="abwaehlen"
         title="Einstellungen der Seite"><?= Util::h(Util::kuerzen((string) $seite['titel'], 40)) ?></a>
      <span class="pille<?= $istOeffentlich ? ' pille--erfolg' : '' ?> pille--punkt"><?= $istOeffentlich ? 'Veröffentlicht' : 'Entwurf' ?></span>
    </div>

    <div class="bau-kopf__mitte">
      <div class="segment" role="group" aria-label="Ansicht" data-nur-mit-js hidden>
        <?php foreach ([['desktop', 'monitor', 'Computer'], ['tablet', 'tablet', 'Tablet'], ['mobil', 'phone-device', 'Handy']] as [$g, $ikon, $name]): ?>
          <button type="button" class="segment__teil<?= $g === 'desktop' ? ' ist-aktiv' : '' ?>" data-geraet="<?= $g ?>"
                  title="<?= $name ?>" aria-label="Ansicht: <?= $name ?>" aria-pressed="<?= $g === 'desktop' ? 'true' : 'false' ?>">
            <?= Icon::svg($ikon, 15) ?><span class="bau-kopf__wort"><?= $name ?></span></button>
        <?php endforeach; ?>
      </div>
      <button type="button" class="bau-kopf__zoom" data-zoom hidden title="Zwischen „Einpassen" und 100 % wechseln">100 %</button>
    </div>

    <div class="bau-kopf__rechts">
      <?php if ($darfSchreiben): ?>
        <div class="bau-kopf__verlauf" data-nur-mit-js hidden>
          <button type="button" class="rundknopf" data-rueckgaengig disabled title="Rückgängig (Strg + Z)" aria-label="Rückgängig"><?= Icon::svg('undo', 16) ?></button>
          <button type="button" class="rundknopf" data-wiederholen disabled title="Wiederholen (Strg + Umschalt + Z)" aria-label="Wiederholen"><?= Icon::svg('redo', 16) ?></button>
        </div>
        <span class="bau-speicher" data-speicher data-nur-mit-js hidden role="status" aria-live="polite"></span>
      <?php endif; ?>
      <a class="btn btn--klein" target="_blank" rel="noopener" href="<?= Util::attr(Pages::url($seite)) ?>">
        <?= Icon::svg('external', 14) ?> <span class="bau-kopf__wort">Ansehen</span></a>
      <?php if (!$istOeffentlich && $darfSchreiben): ?>
        <form method="post" action="<?= Util::attr(App::url('/app/seite.php?id=' . $id)) ?>">
          <?= Auth::csrfFeld() ?>
          <input type="hidden" name="aktion" value="seite_speichern">
          <input type="hidden" name="status" value="veroeffentlicht">
          <button class="btn btn--klein btn--primaer" type="submit" title="Veröffentlichen"><?= Icon::svg('check', 14) ?> <span class="bau-kopf__wort">Veröffentlichen</span></button>
        </form>
      <?php endif; ?>
    </div>
  </header>

  <?php /* ===================================================== Links === */ ?>
  <aside class="bau__links" aria-label="Aufbau der Seite">
    <div class="bau__spalten-kopf">
      <span>Aufbau <span class="bau__zahl" data-anzahl><?= count($bloecke) ?></span></span>
      <?php if ($darfSchreiben): ?>
        <button type="button" class="btn btn--klein btn--primaer" data-vorrat-auf data-nur-mit-js hidden>
          <?= Icon::svg('plus', 14) ?> Baustein</button>
      <?php endif; ?>
    </div>

    <nav class="bau-aufbau" data-aufbau>
      <?php foreach ($bloecke as $b):
        $bauBlock = $b; $bauSeiteId = $id; $bauGewaehlt = $gewaehlt;
        require __DIR__ . '/partials/bau-teil.php';
      endforeach; ?>
      <p class="bau-aufbau__leer" data-aufbau-leer<?= $bloecke !== [] ? ' hidden' : '' ?>>Die Seite ist noch leer.</p>
    </nav>

    <?php if ($darfSchreiben): ?>
      <?php /*
       * Der Vorrat. Ohne JavaScript klappt er unter dem Aufbau auf, und
       * jeder Eintrag ist ein Knopf, der den Baustein hinter den gewählten
       * setzt. Mit JavaScript wird daraus eine Auswahl mit Suche, die neben
       * der Leiste aufgeht – oder an der Stelle, an der man „+" geklickt
       * hat –, und aus der man Bausteine auch in die Seite ziehen kann.
       */ ?>
      <details class="bau-vorrat" data-vorrat>
        <summary><?= Icon::svg('plus', 14) ?> Baustein hinzufügen</summary>
        <div class="bau-vorrat__innen" data-vorrat-innen>
          <div class="bau-vorrat__suche" data-nur-mit-js hidden>
            <?= Icon::svg('search', 15) ?>
            <input class="eingabe" type="search" placeholder="Baustein suchen …" aria-label="Baustein suchen" data-vorrat-suche>
          </div>
          <?php foreach (Bloecke::nachGruppe() as $gruppe => $typen): ?>
            <div class="bau-vorrat__gruppe" data-vorrat-gruppe><?= Util::h($gruppe) ?></div>
            <?php foreach ($typen as $typ): ?>
              <form method="post" action="<?= Util::attr(App::url('/app/seite.php?id=' . $id)) ?>" class="bau-vorrat__form">
                <?= Auth::csrfFeld() ?>
                <input type="hidden" name="aktion" value="block_hinzu">
                <input type="hidden" name="typ" value="<?= Util::attr($typ) ?>">
                <input type="hidden" name="nach" value="<?= Util::attr($gewaehlterBlock !== null ? $gewaehlt : '') ?>">
                <button class="bau-vorrat__teil" type="submit" data-neuer-typ="<?= Util::attr($typ) ?>"
                        data-name="<?= Util::attr(Bloecke::name($typ)) ?>"
                        data-suche="<?= Util::attr(mb_strtolower(Bloecke::name($typ) . ' ' . $gruppe . ' ' . Bloecke::beschreibung($typ))) ?>">
                  <span class="bau-vorrat__symbol"><?= Icon::svg(Bloecke::icon($typ), 16) ?></span>
                  <span class="bau-vorrat__text">
                    <span class="bau-vorrat__name"><?= Util::h(Bloecke::name($typ)) ?></span>
                    <span class="bau-vorrat__satz"><?= Util::h(Bloecke::beschreibung($typ)) ?></span>
                  </span>
                </button>
              </form>
            <?php endforeach; ?>
          <?php endforeach; ?>
          <p class="bau-vorrat__nichts" data-vorrat-nichts hidden>Kein Baustein passt zu dieser Suche.</p>
        </div>
      </details>
    <?php endif; ?>
  </aside>

  <?php /* ===================================================== Mitte === */ ?>
  <main class="bau__buehne" data-buehne>
    <div class="bau__rolle" data-rolle>
      <div class="bau__flaeche">
        <div class="bau__massstab" data-massstab>
          <?php
          /*
           * Ab hier ist die Vorschau das Formular.
           *
           * `Renderer::bearbeitbar(true)` sorgt dafür, dass jedes Feld,
           * das sich an Ort und Stelle ändern lässt, seine Herkunft mit ins
           * HTML bekommt. Die Leinwand ist ein Container: Die Website-Stile
           * rechnen darin mit ihrer Breite statt mit der des Fensters
           * (`--vw: 1cqi`) – so zeigt „Handy" wirklich, was ein Handy zeigt.
           */
          Renderer::bearbeitbar($darfSchreiben);
          ?>
          <div class="bau__leinwand" id="leinwand" data-geraet="desktop" data-seite="<?= $darfSchreiben ? (int) $id : '' ?>">
            <link rel="stylesheet" href="<?= Util::attr(App::asset('assets/css/site.css')) ?>">
            <div class="seite" style="<?= Util::attr(Website::stilVariablen()) ?>" data-bloecke>
              <?php foreach ($bloecke as $b):
                $bauBlock = $b; $bauGewaehlt = $gewaehlt;
                require __DIR__ . '/partials/bau-block.php';
              endforeach; ?>
            </div>
            <div class="bau-leer" data-leer<?= $bloecke !== [] ? ' hidden' : '' ?>>
              <div class="bau-leer__symbol"><?= Icon::svg('layers', 28) ?></div>
              <h3>Noch nichts auf dieser Seite</h3>
              <p>Füge den ersten Baustein hinzu – am besten einen Titelbereich. Er entscheidet, ob jemand weiterliest.</p>
              <?php if ($darfSchreiben): ?>
                <button type="button" class="btn btn--primaer" data-einfuegen="" data-nur-mit-js hidden>
                  <?= Icon::svg('plus', 15) ?> Baustein hinzufügen</button>
              <?php endif; ?>
            </div>
          </div>
          <?php Renderer::bearbeitbar(false); ?>
        </div>
        <?php if ($darfSchreiben): ?>
          <button type="button" class="bau-ende" data-einfuegen="" data-nur-mit-js hidden<?= $bloecke === [] ? ' data-leer-versteckt' : '' ?>>
            <?= Icon::svg('plus', 15) ?> Baustein am Ende hinzufügen</button>
        <?php endif; ?>
      </div>
    </div>
    <div class="bau__ueber" data-ueber></div>
  </main>

  <?php /* ==================================================== Rechts === */ ?>
  <aside class="bau__rechts" data-rechts aria-label="Eigenschaften">
    <?php
    $bauSeite = $seite; $bauBloecke = $bloecke; $bauBlock = $gewaehlterBlock; $bauSchreiben = $darfSchreiben;
    require __DIR__ . '/partials/bau-panel.php';
    ?>
  </aside>
</div>

<?php
$skripte = '<script src="' . Util::attr(App::asset('assets/js/bauen.js')) . '"></script>';
require __DIR__ . '/partials/fuss.php';
?>
