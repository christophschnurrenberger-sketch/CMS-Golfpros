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
    App::weiter('/app/seite.php?id=' . $neuId);
}

$bloecke = Pages::bloecke($seite);
$gewaehlt = App::get('block');

if (App::istPost()) {
    Auth::csrfFordern();
    Auth::fordern('website.write');
    $aktion = App::aktion();

    /* Umsortieren per Ziehen meldet sich als einfacher POST. */
    if ($aktion === 'reihenfolge') {
        $neu = [];
        foreach (explode(',', App::post('reihenfolge')) as $blockId) {
            foreach ($bloecke as $b) {
                if ((string) ($b['id'] ?? '') === $blockId) {
                    $neu[] = $b;
                }
            }
        }
        if (count($neu) === count($bloecke)) {
            Pages::speichern(['bloecke' => Util::json($neu)], $id);
        }
        exit;
    }

    if ($aktion === 'block_hinzu') {
        $neuerBlock = Bloecke::neu(App::post('typ'));
        $position = App::post('nach');
        if ($position === '') {
            $bloecke[] = $neuerBlock;
        } elseif ($position === 'anfang') {
            /* Beim Ziehen an den oberen Rand gibt es keinen Baustein,
               hinter den man einfuegen koennte. */
            array_unshift($bloecke, $neuerBlock);
        } else {
            $eingefuegt = [];
            foreach ($bloecke as $b) {
                $eingefuegt[] = $b;
                if ((string) ($b['id'] ?? '') === $position) {
                    $eingefuegt[] = $neuerBlock;
                }
            }
            $bloecke = $eingefuegt;
        }
        Pages::speichern(['bloecke' => Util::json($bloecke)], $id);
        App::weiter('/app/seite.php?id=' . $id . '&block=' . $neuerBlock['id']);
    }

    if ($aktion === 'block_speichern') {
        $blockId = App::post('block_id');
        foreach ($bloecke as $i => $b) {
            if ((string) ($b['id'] ?? '') !== $blockId) {
                continue;
            }
            $typ = Bloecke::typ((string) $b['typ']);
            $daten = [];
            foreach ((array) ($typ['felder'] ?? []) as $feld => $def) {
                $art = $def[1] ?? 'text';
                if ($art === 'liste') {
                    $eintraege = [];
                    $unterfelder = array_keys((array) ($def[2] ?? []));
                    $anzahl = count(App::postListe('l_' . $feld . '_' . ($unterfelder[0] ?? 'x')));
                    for ($n = 0; $n < $anzahl; $n++) {
                        $eintrag = [];
                        $leer = true;
                        foreach ($unterfelder as $uf) {
                            $werte = App::postListe('l_' . $feld . '_' . $uf);
                            $eintrag[$uf] = (string) ($werte[$n] ?? '');
                            if (trim($eintrag[$uf]) !== '') {
                                $leer = false;
                            }
                        }
                        if (!$leer) {
                            $eintraege[] = $eintrag;
                        }
                    }
                    $daten[$feld] = $eintraege;
                } elseif ($art === 'schalter') {
                    $daten[$feld] = App::postBool('f_' . $feld);
                } elseif ($art === 'zahl') {
                    $daten[$feld] = App::postInt('f_' . $feld);
                } else {
                    $daten[$feld] = App::postRoh('f_' . $feld);
                }
            }
            $bloecke[$i]['daten'] = $daten;
        }
        Pages::speichern(['bloecke' => Util::json($bloecke)], $id);
        App::melden('Baustein gespeichert.');
        App::weiter('/app/seite.php?id=' . $id . '&block=' . $blockId);
    }

    if ($aktion === 'block_weg') {
        $blockId = App::post('block_id');
        $bloecke = array_values(array_filter($bloecke,
            static fn($b) => (string) ($b['id'] ?? '') !== $blockId));
        Pages::speichern(['bloecke' => Util::json($bloecke)], $id);
        App::weiter('/app/seite.php?id=' . $id);
    }

    if ($aktion === 'block_kopie') {
        $blockId = App::post('block_id');
        $neu = [];
        foreach ($bloecke as $b) {
            $neu[] = $b;
            if ((string) ($b['id'] ?? '') === $blockId) {
                $kopie = $b;
                $kopie['id'] = 'b' . substr(Util::token(4), 0, 7);
                $neu[] = $kopie;
            }
        }
        Pages::speichern(['bloecke' => Util::json($neu)], $id);
        App::weiter('/app/seite.php?id=' . $id);
    }

    if ($aktion === 'block_schieben') {
        $blockId = App::post('block_id');
        $richtung = App::post('richtung') === 'hoch' ? -1 : 1;
        foreach ($bloecke as $i => $b) {
            if ((string) ($b['id'] ?? '') !== $blockId) {
                continue;
            }
            $ziel = $i + $richtung;
            if ($ziel >= 0 && $ziel < count($bloecke)) {
                [$bloecke[$i], $bloecke[$ziel]] = [$bloecke[$ziel], $bloecke[$i]];
            }
            break;
        }
        Pages::speichern(['bloecke' => Util::json($bloecke)], $id);
        App::weiter('/app/seite.php?id=' . $id . '&block=' . $blockId);
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
        Pages::speichern([
            'titel' => App::post('titel'), 'slug' => App::post('slug'),
            'status' => App::post('status'), 'im_menue' => App::postBool('im_menue') ? 1 : 0,
            'art' => App::post('art', 'seite'),
            'seo' => Util::json([
                'titel' => App::post('seo_titel'),
                'beschreibung' => App::post('seo_beschreibung'),
                'index' => App::postBool('seo_index'),
            ]),
        ], $id);
        App::melden('Seite gespeichert.');
        App::weiter('/app/seite.php?id=' . $id . '&block=' . $gewaehlt);
    }

    if ($aktion === 'startseite') {
        Pages::startseiteSetzen($id);
        App::melden('Das ist jetzt die Startseite.');
        App::weiter('/app/seite.php?id=' . $id);
    }

    if ($aktion === 'loeschen') {
        if (Pages::loeschen($id)) {
            App::melden('Seite gelöscht.');
            App::weiter('/app/website.php');
        }
        App::melden('Die Startseite lässt sich nicht löschen. Mach zuerst eine andere Seite zur Startseite.', 'fehler');
        App::weiter('/app/seite.php?id=' . $id);
    }
}

$gewaehlterBlock = null;
foreach ($bloecke as $b) {
    if ((string) ($b['id'] ?? '') === $gewaehlt) {
        $gewaehlterBlock = $b;
    }
}
$seo = Pages::seo($seite);

$titel = (string) $seite['titel'];
$ohneKopf = true;
$inhaltKlasse = 'inhalt--rand-los';
$bereich = 'website';
require __DIR__ . '/partials/kopf.php';
?>

<div class="bau">

  <?php /* ===================================================== Links === */ ?>
  <div class="bau__spalte">
    <div class="bau__spalte-kopf">
      <div class="reihe reihe--eng">
        <a href="<?= Util::attr(App::url('/app/website.php')) ?>" class="gedimmt"><?= Icon::svg('arrow-left', 14) ?></a>
        <span style="flex:1;text-transform:none;letter-spacing:0;font-size:13px;color:var(--text);font-weight:600">
          <?= Util::h(Util::kuerzen((string) $seite['titel'], 22)) ?></span>
      </div>
    </div>

    <div class="reiter" style="margin:0;padding:0 var(--r3)" data-reiter-gruppe="bau">
      <button class="reiter__teil ist-aktiv" data-reiter="aufbau">Aufbau</button>
      <button class="reiter__teil" data-reiter="vorrat">Hinzufügen</button>
    </div>

    <div data-reiter-feld="aufbau" data-reiter-gruppe="bau" style="flex:1;overflow-y:auto">
      <div class="bau-liste" data-bau-ziel
           data-sortierbar="<?= Util::attr(App::url('/app/seite.php?id=' . $id)) ?>">
        <?php foreach ($bloecke as $b): ?>
          <a class="bau-liste__teil<?= (string) $b['id'] === $gewaehlt ? ' ist-gewaehlt' : '' ?>"
             href="<?= Util::attr(App::url('/app/seite.php?id=' . $id . '&block=' . $b['id'])) ?>"
             draggable="true" data-ziehbar data-id="<?= Util::attr((string) $b['id']) ?>">
            <span class="bau-liste__griff"><?= Icon::svg('grip', 14) ?></span>
            <?= Icon::svg(Bloecke::icon((string) $b['typ']), 15) ?>
            <span style="flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
              <?= Util::h(Bloecke::name((string) $b['typ'])) ?></span>
          </a>
        <?php endforeach; ?>
        <?php if ($bloecke === []): ?>
          <p class="klein gedimmt mitte" style="padding:var(--r5) var(--r3)">
            Die Seite ist leer.<br>Wechsle zu <em>Hinzufügen</em>.</p>
        <?php endif; ?>
      </div>
    </div>

    <div data-reiter-feld="vorrat" data-reiter-gruppe="bau" class="versteckt" style="flex:1;overflow-y:auto">
      <?php foreach (Bloecke::nachGruppe() as $gruppe => $typen): ?>
        <div class="bau-vorrat__gruppe"><?= Util::h($gruppe) ?></div>
        <div class="bau-vorrat">
          <?php foreach ($typen as $typ): ?>
            <?php /*
             * Zwei Wege zum selben Ziel: Klicken haengt den Baustein hinter
             * den gerade gewaehlten, Ziehen setzt ihn dorthin, wo man ihn
             * fallen laesst. Der Knopf bleibt ein echter Submit-Knopf -
             * ohne JavaScript funktioniert der Baukasten weiter.
             */ ?>
            <form method="post">
              <?= Auth::csrfFeld() ?>
              <input type="hidden" name="aktion" value="block_hinzu">
              <input type="hidden" name="typ" value="<?= Util::attr($typ) ?>">
              <input type="hidden" name="nach" value="<?= Util::attr($gewaehlt) ?>">
              <button class="bau-vorrat__teil" type="submit"
                      draggable="true" data-neuer-typ="<?= Util::attr($typ) ?>"
                      title="Klicken oder in die Seite ziehen">
                <?= Icon::svg(Bloecke::icon($typ), 16) ?>
                <span><?= Util::h(Bloecke::name($typ)) ?></span>
                <span class="fueller"></span>
                <?= Icon::svg('plus', 13) ?>
              </button>
            </form>
          <?php endforeach; ?>
        </div>
      <?php endforeach; ?>
    </div>
  </div>

  <?php /* ===================================================== Mitte === */ ?>
  <div class="bau__buehne">
    <div style="width:100%;max-width:1100px">
      <div class="reihe mb-4">
        <div class="geraete-wahl segment">
          <button class="segment__teil ist-aktiv" data-geraet="desktop" title="Desktop">
            <?= Icon::svg('monitor', 15) ?></button>
          <button class="segment__teil" data-geraet="tablet" title="Tablet">
            <?= Icon::svg('tablet', 15) ?></button>
          <button class="segment__teil" data-geraet="mobil" title="Smartphone">
            <?= Icon::svg('phone-device', 15) ?></button>
        </div>
        <div class="fueller"></div>
        <span class="klein gedimmt"><?= count($bloecke) ?> Bausteine</span>
        <a class="btn btn--klein" target="_blank" rel="noopener"
           href="<?= Util::attr(Pages::url($seite)) ?>"><?= Icon::svg('external', 14) ?> Ansehen</a>
        <?php if ((string) $seite['status'] !== 'veroeffentlicht' && Auth::darf('website.write')): ?>
          <form method="post" style="display:inline">
            <?= Auth::csrfFeld() ?>
            <input type="hidden" name="aktion" value="seite_speichern">
            <input type="hidden" name="titel" value="<?= Util::attr((string) $seite['titel']) ?>">
            <input type="hidden" name="slug" value="<?= Util::attr((string) $seite['slug']) ?>">
            <input type="hidden" name="art" value="<?= Util::attr((string) $seite['art']) ?>">
            <input type="hidden" name="im_menue" value="<?= (int) $seite['im_menue'] ?>">
            <input type="hidden" name="seo_titel" value="<?= Util::attr((string) $seo['titel']) ?>">
            <input type="hidden" name="seo_beschreibung" value="<?= Util::attr((string) $seo['beschreibung']) ?>">
            <input type="hidden" name="seo_index" value="1">
            <input type="hidden" name="status" value="veroeffentlicht">
            <button class="btn btn--klein btn--primaer" type="submit">
              <?= Icon::svg('check', 14) ?> Veröffentlichen</button>
          </form>
        <?php endif; ?>
      </div>

      <div class="bau__leinwand" id="leinwand" data-geraet="desktop" data-bau-ziel>
        <link rel="stylesheet" href="<?= Util::attr(App::asset('assets/css/site.css')) ?>">
        <div class="seite" style="<?= Util::attr(Website::stilVariablen()) ?>">
          <?php if ($bloecke === []): ?>
            <div style="padding:80px 24px;text-align:center;color:#76766f">
              <div style="margin-bottom:16px"><?= Icon::svg('layers', 30) ?></div>
              <h3 style="margin-bottom:8px">Noch nichts auf dieser Seite</h3>
              <p style="max-width:32em;margin:0 auto">Wähle links unter <strong>Hinzufügen</strong>
                einen Baustein – anklicken oder hierher ziehen. Beginn mit einem
                Titelbereich; er entscheidet, ob jemand weiterliest.</p>
            </div>
          <?php else: ?>
            <?php foreach ($bloecke as $i => $b): ?>
              <div class="bau-block<?= (string) $b['id'] === $gewaehlt ? ' ist-gewaehlt' : '' ?>"
                   id="block-<?= Util::attr((string) $b['id']) ?>"
                   data-block-id="<?= Util::attr((string) $b['id']) ?>"
                   onclick="location.href='<?= Util::attr(App::url('/app/seite.php?id=' . $id . '&block=' . $b['id'])) ?>'">
                <span class="bau-block__marke"><?= Util::h(Bloecke::name((string) $b['typ'])) ?></span>
                <?php if (Auth::darf('website.write')): ?>
                <span class="bau-block__leiste" onclick="event.stopPropagation()">
                  <?php foreach ([['hoch', 'chevron-up', 'Nach oben'], ['runter', 'chevron-down', 'Nach unten']] as [$r, $ikon, $label]): ?>
                    <form method="post" style="display:inline">
                      <?= Auth::csrfFeld() ?>
                      <input type="hidden" name="aktion" value="block_schieben">
                      <input type="hidden" name="block_id" value="<?= Util::attr((string) $b['id']) ?>">
                      <input type="hidden" name="richtung" value="<?= $r ?>">
                      <button class="bau-block__knopf" type="submit" aria-label="<?= $label ?>">
                        <?= Icon::svg($ikon, 14) ?></button>
                    </form>
                  <?php endforeach; ?>
                  <form method="post" style="display:inline">
                    <?= Auth::csrfFeld() ?>
                    <input type="hidden" name="aktion" value="block_kopie">
                    <input type="hidden" name="block_id" value="<?= Util::attr((string) $b['id']) ?>">
                    <button class="bau-block__knopf" type="submit" aria-label="Kopieren">
                      <?= Icon::svg('copy', 14) ?></button>
                  </form>
                  <form method="post" style="display:inline" data-bestaetigen="Diesen Baustein entfernen?">
                    <?= Auth::csrfFeld() ?>
                    <input type="hidden" name="aktion" value="block_weg">
                    <input type="hidden" name="block_id" value="<?= Util::attr((string) $b['id']) ?>">
                    <button class="bau-block__knopf" type="submit" aria-label="Entfernen">
                      <?= Icon::svg('trash', 14) ?></button>
                  </form>
                </span>
                <?php endif; ?>
                <?= Renderer::block($b) ?>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>

  <?php /* ==================================================== Rechts === */ ?>
  <div class="bau__spalte bau__spalte--rechts">
    <?php if ($gewaehlterBlock !== null):
      $typ = Bloecke::typ((string) $gewaehlterBlock['typ']);
      $daten = (array) ($gewaehlterBlock['daten'] ?? []); ?>
      <div class="bau__spalte-kopf">
        <div class="reihe reihe--eng">
          <?= Icon::svg(Bloecke::icon((string) $gewaehlterBlock['typ']), 15) ?>
          <span style="flex:1;text-transform:none;letter-spacing:0;font-size:13px;color:var(--text);font-weight:600">
            <?= Util::h(Bloecke::name((string) $gewaehlterBlock['typ'])) ?></span>
          <a href="<?= Util::attr(App::url('/app/seite.php?id=' . $id)) ?>" class="gedimmt"
             aria-label="Auswahl aufheben"><?= Icon::svg('x', 15) ?></a>
        </div>
      </div>
      <form method="post" style="flex:1;overflow-y:auto;display:flex;flex-direction:column">
        <?= Auth::csrfFeld() ?>
        <input type="hidden" name="aktion" value="block_speichern">
        <input type="hidden" name="block_id" value="<?= Util::attr((string) $gewaehlterBlock['id']) ?>">
        <div style="padding:var(--r4);flex:1">
          <?php foreach ((array) ($typ['felder'] ?? []) as $feld => $def):
            $label = $def[0];
            $art   = $def[1] ?? 'text';
            $zusatz = $def[2] ?? null;
            $wert = $daten[$feld] ?? '';
            $fid = 'f_' . $feld; ?>

            <?php if ($art === 'liste'): ?>
              <div class="feld">
                <span class="feld__label"><?= Util::h($label) ?></span>
                <div class="stapel stapel--eng" id="liste-<?= Util::attr($feld) ?>">
                  <?php
                  $eintraege = is_array($wert) ? $wert : [];
                  $eintraege[] = [];   // eine leere Zeile zum Anhängen
                  foreach ($eintraege as $n => $eintrag): ?>
                    <div style="border:1px solid var(--rand);border-radius:var(--radius);padding:var(--r3)">
                      <?php foreach ((array) $zusatz as $uf => $udef):
                        $ulabel = $udef[0];
                        $uart = $udef[1] ?? 'text';
                        $uwert = (string) ($eintrag[$uf] ?? ''); ?>
                        <div class="feld" style="margin-bottom:var(--r2)">
                          <label class="feld__label" style="font-size:11.5px"><?= Util::h($ulabel) ?></label>
                          <?php if ($uart === 'mehrzeilig'): ?>
                            <textarea class="eingabe" name="l_<?= Util::attr($feld . '_' . $uf) ?>[]"
                                      rows="2" data-waechst><?= Util::h($uwert) ?></textarea>
                          <?php elseif ($uart === 'auswahl' && $udef[2] === 'icons'): ?>
                            <select name="l_<?= Util::attr($feld . '_' . $uf) ?>[]">
                              <option value="">—</option>
                              <?php foreach (['target', 'video', 'training', 'award', 'check', 'star',
                                              'clock', 'customers', 'euro', 'flag', 'trophy', 'zap'] as $ikon): ?>
                                <option value="<?= $ikon ?>"<?= $uwert === $ikon ? ' selected' : '' ?>><?= $ikon ?></option>
                              <?php endforeach; ?>
                            </select>
                          <?php elseif ($uart === 'schalter'): ?>
                            <select name="l_<?= Util::attr($feld . '_' . $uf) ?>[]">
                              <option value=""<?= $uwert === '' ? ' selected' : '' ?>>Nein</option>
                              <option value="1"<?= $uwert !== '' ? ' selected' : '' ?>>Ja</option>
                            </select>
                          <?php else: ?>
                            <input class="eingabe" name="l_<?= Util::attr($feld . '_' . $uf) ?>[]"
                                   value="<?= Util::attr($uwert) ?>">
                          <?php endif; ?>
                        </div>
                      <?php endforeach; ?>
                    </div>
                  <?php endforeach; ?>
                </div>
                <div class="feld__hinweis">Die letzte, leere Karte fügt einen weiteren Eintrag hinzu.
                  Eintrag leeren entfernt ihn.</div>
              </div>

            <?php elseif ($art === 'mehrzeilig'): ?>
              <div class="feld">
                <label class="feld__label" for="<?= $fid ?>"><?= Util::h($label) ?></label>
                <textarea class="eingabe" id="<?= $fid ?>" name="<?= $fid ?>" rows="4"
                          data-waechst><?= Util::h((string) $wert) ?></textarea>
              </div>

            <?php elseif ($art === 'auswahl'): ?>
              <div class="feld">
                <label class="feld__label" for="<?= $fid ?>"><?= Util::h($label) ?></label>
                <select id="<?= $fid ?>" name="<?= $fid ?>">
                  <?php foreach ((array) $zusatz as $k => $v): ?>
                    <option value="<?= Util::attr((string) $k) ?>"<?= (string) $wert === (string) $k ? ' selected' : '' ?>>
                      <?= Util::h((string) $v) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>

            <?php elseif ($art === 'schalter'): ?>
              <label class="haken mb-4">
                <input type="checkbox" name="<?= $fid ?>" value="1"<?= !empty($wert) ? ' checked' : '' ?>>
                <span class="haken__text"><?= Util::h($label) ?></span>
              </label>

            <?php elseif ($art === 'zahl'): ?>
              <div class="feld">
                <label class="feld__label" for="<?= $fid ?>"><?= Util::h($label) ?></label>
                <input class="eingabe" id="<?= $fid ?>" type="number" name="<?= $fid ?>"
                       value="<?= Util::attr((string) $wert) ?>">
              </div>

            <?php elseif ($art === 'bild'): ?>
              <div class="feld">
                <label class="feld__label" for="<?= $fid ?>"><?= Util::h($label) ?></label>
                <input class="eingabe" id="<?= $fid ?>" name="<?= $fid ?>"
                       value="<?= Util::attr((string) $wert) ?>" placeholder="uploads/… oder https://">
                <div class="feld__hinweis">
                  <a href="<?= Util::attr(App::url('/app/medien.php')) ?>" target="_blank" rel="noopener">
                    Mediathek öffnen</a> und Pfad einfügen.</div>
              </div>

            <?php else: ?>
              <div class="feld">
                <label class="feld__label" for="<?= $fid ?>"><?= Util::h($label) ?></label>
                <input class="eingabe" id="<?= $fid ?>" name="<?= $fid ?>"
                       type="<?= $art === 'url' ? 'text' : 'text' ?>"
                       value="<?= Util::attr((string) $wert) ?>">
              </div>
            <?php endif; ?>
          <?php endforeach; ?>
        </div>
        <?php if (Auth::darf('website.write')): ?>
          <div style="position:sticky;bottom:0;padding:var(--r3) var(--r4);
                      border-top:1px solid var(--rand);background:var(--flaeche)">
            <button class="btn btn--primaer btn--voll" type="submit">Baustein speichern</button>
          </div>
        <?php endif; ?>
      </form>

    <?php else: ?>
      <div class="bau__spalte-kopf">Seite</div>
      <form method="post" style="flex:1;overflow-y:auto;display:flex;flex-direction:column">
        <?= Auth::csrfFeld() ?>
        <input type="hidden" name="aktion" value="seite_speichern">
        <div style="padding:var(--r4);flex:1">
          <div class="feld"><label class="feld__label" for="s-titel">Titel</label>
            <input class="eingabe" id="s-titel" name="titel" value="<?= Util::attr((string) $seite['titel']) ?>"></div>
          <div class="feld"><label class="feld__label" for="s-slug">Adresse</label>
            <div class="eingabe-gruppe">
              <span class="eingabe-gruppe__anhang eingabe-gruppe__anhang--vorn">/</span>
              <input class="eingabe" id="s-slug" name="slug" value="<?= Util::attr((string) $seite['slug']) ?>">
            </div></div>
          <div class="feld"><label class="feld__label" for="s-status">Status</label>
            <select id="s-status" name="status">
              <option value="entwurf"<?= (string) $seite['status'] === 'entwurf' ? ' selected' : '' ?>>
                Entwurf – nicht öffentlich</option>
              <option value="veroeffentlicht"<?= (string) $seite['status'] === 'veroeffentlicht' ? ' selected' : '' ?>>
                Veröffentlicht</option>
            </select></div>
          <div class="feld"><label class="feld__label" for="s-art">Art</label>
            <select id="s-art" name="art">
              <option value="seite"<?= (string) $seite['art'] === 'seite' ? ' selected' : '' ?>>Normale Seite</option>
              <option value="landingpage"<?= (string) $seite['art'] === 'landingpage' ? ' selected' : '' ?>>Landingpage</option>
            </select></div>
          <?php if ((int) $seite['startseite'] !== 1): ?>
          <?php $moeglich = Pages::elternMoeglich($id); ?>
          <div class="feld"><label class="feld__label" for="s-eltern">Untergeordnet</label>
            <select id="s-eltern" name="parent_id">
              <option value="0">— oberste Ebene —</option>
              <?php foreach ($moeglich as $m): ?>
                <option value="<?= (int) $m['id'] ?>"<?= (int) $seite['parent_id'] === (int) $m['id'] ? ' selected' : '' ?>>
                  <?= str_repeat('— ', (int) $m['tiefe']) ?><?= Util::h((string) $m['titel']) ?></option>
              <?php endforeach; ?>
            </select>
            <div class="feld__hinweis">
              <?php if ($moeglich === []): ?>
                Diese Seite hat selbst schon Unterseiten über
                <?= Pages::MAX_TIEFE ?> Ebenen – tiefer geht es nicht.
              <?php else: ?>
                Im Menü steht sie dann im Klappmenü dieser Seite.
                Bis zu <?= Pages::MAX_TIEFE ?> Ebenen; die Adresse bleibt
                <code>/<?= Util::h((string) $seite['slug']) ?></code>.
              <?php endif; ?></div></div>
          <?php endif; ?>
          <label class="haken mb-4">
            <input type="checkbox" name="im_menue" value="1"<?= (int) $seite['im_menue'] === 1 ? ' checked' : '' ?>>
            <span class="haken__text">Im Menü zeigen
              <span class="haken__hinweis">Aus heißt: erreichbar, aber nicht in der Navigation.
                Unterseiten einer ausgeblendeten Seite rücken im Menü eine Ebene hoch.</span></span>
          </label>

          <hr>
          <div class="versal gedimmt-2 mb-3">Für Suchmaschinen</div>
          <div class="feld"><label class="feld__label" for="s-seo-titel">Seitentitel</label>
            <input class="eingabe" id="s-seo-titel" name="seo_titel" maxlength="70"
                   value="<?= Util::attr((string) $seo['titel']) ?>" data-zaehler="zaehler-titel">
            <div class="feld__hinweis" id="zaehler-titel"></div></div>
          <div class="feld"><label class="feld__label" for="s-seo-text">Beschreibung</label>
            <textarea class="eingabe" id="s-seo-text" name="seo_beschreibung" rows="3" maxlength="170"
                      data-zaehler="zaehler-text" data-waechst><?= Util::h((string) $seo['beschreibung']) ?></textarea>
            <div class="feld__hinweis" id="zaehler-text"></div></div>
          <label class="haken mb-4">
            <input type="checkbox" name="seo_index" value="1"<?= !empty($seo['index']) ? ' checked' : '' ?>>
            <span class="haken__text">Von Google indexieren lassen</span>
          </label>

          <div class="klein gedimmt mt-5">
            <div class="reihe"><span>Aufrufe</span><div class="fueller"></div>
              <span class="halbfett"><?= Util::zahl((int) $seite['aufrufe']) ?></span></div>
            <?php $conv = Pages::conversion($seite); ?>
            <div class="reihe mt-2"><span>Anfragen</span><div class="fueller"></div>
              <span class="halbfett"><?= (int) $seite['conversions'] ?><?= $conv !== null
                ? ' (' . Util::prozent($conv, 1) . ')' : '' ?></span></div>
          </div>
        </div>
        <?php if (Auth::darf('website.write')): ?>
          <div style="position:sticky;bottom:0;padding:var(--r3) var(--r4);
                      border-top:1px solid var(--rand);background:var(--flaeche)">
            <button class="btn btn--primaer btn--voll" type="submit">Seite speichern</button>
          </div>
        <?php endif; ?>
      </form>

      <?php if (Auth::darf('website.write')): ?>
        <div style="padding:0 var(--r4) var(--r4)">
          <?php if ((int) $seite['startseite'] === 0): ?>
            <form method="post" class="mb-2">
              <?= Auth::csrfFeld() ?>
              <input type="hidden" name="aktion" value="startseite">
              <button class="btn btn--voll btn--klein" type="submit">
                <?= Icon::svg('home', 14) ?> Zur Startseite machen</button>
            </form>
            <form method="post" data-bestaetigen="Diese Seite löschen?">
              <?= Auth::csrfFeld() ?>
              <input type="hidden" name="aktion" value="loeschen">
              <button class="btn btn--voll btn--klein" type="submit" style="color:var(--gefahr)">
                <?= Icon::svg('trash', 14) ?> Seite löschen</button>
            </form>
          <?php else: ?>
            <div class="hinweis hinweis--still">
              <?= Icon::svg('home', 15) ?>
              <div class="hinweis__text klein">Das ist die Startseite.</div>
            </div>
          <?php endif; ?>
        </div>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</div>

<script>
document.querySelectorAll('[data-geraet]').forEach(function (k) {
  k.addEventListener('click', function () {
    document.getElementById('leinwand').dataset.geraet = k.dataset.geraet;
    document.querySelectorAll('[data-geraet]').forEach(function (x) { x.classList.remove('ist-aktiv'); });
    k.classList.add('ist-aktiv');
  });
});
</script>

<?php require __DIR__ . '/partials/fuss.php'; ?>
