<?php
/** Marketing-Überblick: Kampagnen, Formulare, Kanäle. */
require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/partials/helfer.php';

Auth::fordern('modul.marketing');

if (App::istPost()) {
    Auth::csrfFordern();
    Auth::fordern('marketing.write');
    if (App::aktion() === 'formular') {
        Tenant::insert('forms', [
            'name' => App::post('name') ?: 'Neues Formular',
            'felder' => Util::json([
                ['name' => 'name', 'label' => 'Name', 'typ' => 'text', 'pflicht' => true],
                ['name' => 'email', 'label' => 'E-Mail', 'typ' => 'email', 'pflicht' => true],
                ['name' => 'telefon', 'label' => 'Telefon', 'typ' => 'tel'],
                ['name' => 'nachricht', 'label' => 'Nachricht', 'typ' => 'mehrzeilig'],
            ]),
            'ziel' => 'lead',
            'bestaetigung' => App::post('bestaetigung') ?: 'Danke! Ich melde mich in Kürze.',
        ]);
        App::melden('Formular angelegt. Füg es als Baustein auf einer Seite ein.');
    }
    App::weiter('/app/marketing.php');
}

$z30 = ['von' => date('Y-m-d', strtotime('-29 days')), 'bis' => Util::heute()];
$besucher = Analytics::besucher($z30['von'], $z30['bis']);
$leads30 = Tenant::count('leads', 'erstellt >= :seit', ['seit' => $z30['von']]);
$kunden30 = Tenant::count('customers', 'erstellt >= :seit', ['seit' => $z30['von']]);
$quellen = Analytics::quellen($z30['von'], $z30['bis']);
$kampagnen = Tenant::all('campaigns', '', [], 'id DESC', 5);
$automationen = Tenant::all('automations', '', [], 'status DESC, id DESC', 5);
$formulare = Tenant::all('forms', '', [], 'id DESC');
$landingpages = Pages::alle('landingpage');

$titel = 'Marketing';
$unter = $besucher . ' Besucher · ' . $leads30 . ' Anfragen · ' . $kunden30 . ' neue Kunden in 30 Tagen';
require __DIR__ . '/partials/kopf.php';
?>

<div class="raster raster--4 mb-5">
  <?= kennzahl('Besucher', Util::zahl($besucher), ['icon' => 'website', 'fuss' => '30 Tage']) ?>
  <?= kennzahl('Anfragen', Util::zahl($leads30), ['icon' => 'leads',
        'fuss' => $besucher > 0 ? Util::prozent($leads30 / $besucher * 100, 1) . ' der Besucher' : '',
        'url' => '/app/leads.php']) ?>
  <?= kennzahl('Neue Kunden', Util::zahl($kunden30), ['icon' => 'user-plus',
        'fuss' => $leads30 > 0 ? Util::prozent($kunden30 / max(1, $leads30) * 100, 0) . ' der Anfragen' : '']) ?>
  <?= kennzahl('Newsletter-Empfänger', Util::zahl(Tenant::count('customers', 'newsletter = 1 AND status = "aktiv"')),
        ['icon' => 'newsletter', 'fuss' => 'mit Einwilligung', 'url' => '/app/newsletter.php']) ?>
</div>

<div class="raster raster--haupt-neben">
  <div class="stapel">
    <div class="karte">
      <div class="karte__kopf"><h2>Kanäle</h2>
        <div class="fueller"></div><span class="klein gedimmt">letzte 30 Tage</span></div>
      <div class="karte__koerper">
        <?php if ($quellen === []): ?>
          <p class="klein gedimmt mitte">Noch keine Besucherdaten.</p>
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
    </div>

    <div class="raster raster--2">
      <div class="karte">
        <div class="karte__kopf"><h3>Newsletter</h3>
          <div class="fueller"></div>
          <a class="klein" href="<?= Util::attr(App::url('/app/newsletter.php')) ?>">alle</a></div>
        <div class="karte__koerper karte__koerper--eng">
          <?php if ($kampagnen === []): ?>
            <p class="klein gedimmt mitte" style="padding:var(--r3) 0">Noch kein Newsletter.</p>
          <?php else: ?>
            <div class="stapel stapel--eng">
              <?php foreach ($kampagnen as $k): ?>
                <a class="reihe" href="<?= Util::attr(App::url('/app/kampagne.php?id=' . (int) $k['id'])) ?>"
                   style="padding:6px;border-radius:8px;color:inherit">
                  <div style="flex:1;min-width:0">
                    <div class="klein halbfett"><?= Util::h((string) $k['name']) ?></div>
                    <div class="winzig gedimmt-2">
                      <?= (int) $k['empfaenger'] ?> Empfänger
                      <?php if ((int) $k['geoeffnet'] > 0): ?>
                        · <?= Util::prozent(Campaigns::quoten($k)['oeffnung'], 0) ?> geöffnet
                      <?php endif; ?>
                    </div>
                  </div>
                  <?= pille((string) $k['status'], (string) $k['status'] === 'versendet' ? 'erfolg' : '') ?>
                </a>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <div class="karte">
        <div class="karte__kopf"><h3>Automationen</h3>
          <div class="fueller"></div>
          <a class="klein" href="<?= Util::attr(App::url('/app/automationen.php')) ?>">alle</a></div>
        <div class="karte__koerper karte__koerper--eng">
          <?php if ($automationen === []): ?>
            <p class="klein gedimmt mitte" style="padding:var(--r3) 0">Noch keine Automation.</p>
          <?php else: ?>
            <div class="stapel stapel--eng">
              <?php foreach ($automationen as $a): ?>
                <a class="reihe" href="<?= Util::attr(App::url('/app/automation.php?id=' . (int) $a['id'])) ?>"
                   style="padding:6px;border-radius:8px;color:inherit">
                  <div style="flex:1;min-width:0">
                    <div class="klein halbfett"><?= Util::h((string) $a['name']) ?></div>
                    <div class="winzig gedimmt-2"><?= (int) $a['gestartet'] ?> gestartet ·
                      <?= (int) $a['abgeschlossen'] ?> abgeschlossen</div>
                  </div>
                  <?= pille((string) $a['status'], (string) $a['status'] === 'aktiv' ? 'erfolg' : '') ?>
                </a>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <div class="karte">
      <div class="karte__kopf"><h3>Landingpages</h3>
        <div class="fueller"></div>
        <a class="klein" href="<?= Util::attr(App::url('/app/website.php')) ?>">Website</a></div>
      <div class="karte__koerper karte__koerper--eng">
        <?php if ($landingpages === []): ?>
          <p class="klein gedimmt mitte" style="padding:var(--r3) 0">
            Keine Landingpage. Für Anzeigen und Kurse lohnt sich eine Seite ohne Menü.</p>
        <?php else: ?>
          <div class="stapel stapel--eng">
            <?php foreach ($landingpages as $s):
              $quote = Pages::conversion($s); ?>
              <a class="kachel-link" href="<?= Util::attr(App::url('/app/seite.php?id=' . (int) $s['id'])) ?>">
                <span class="kachel-link__symbol" style="width:32px;height:32px"><?= Icon::svg('target', 15) ?></span>
                <div style="flex:1;min-width:0">
                  <div class="kachel-link__titel"><?= Util::h((string) $s['titel']) ?></div>
                  <div class="kachel-link__text"><?= Util::zahl((int) $s['aufrufe']) ?> Aufrufe ·
                    <?= (int) $s['conversions'] ?> Anfragen</div>
                </div>
                <?php if ($quote !== null): ?>
                  <?= pille(Util::prozent($quote, 1), $quote >= 2 ? 'erfolg' : ($quote >= 1 ? 'warnung' : 'gefahr')) ?>
                <?php endif; ?>
              </a>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="stapel">
    <div class="karte">
      <div class="karte__kopf"><h3>Formulare</h3>
        <div class="fueller"></div>
        <?php if (Auth::darf('marketing.write')): ?>
          <button class="btn btn--klein btn--geist" data-modal-auf="modal-formular"><?= Icon::svg('plus', 14) ?></button>
        <?php endif; ?>
      </div>
      <div class="karte__koerper karte__koerper--eng">
        <?php if ($formulare === []): ?>
          <p class="klein gedimmt mitte" style="padding:var(--r3) 0">Kein Formular angelegt.</p>
        <?php else: ?>
          <div class="stapel stapel--eng">
            <?php foreach ($formulare as $f): ?>
              <div class="reihe" style="padding:6px">
                <?= Icon::svg('inbox', 15) ?>
                <div style="flex:1;min-width:0">
                  <div class="klein halbfett"><?= Util::h((string) $f['name']) ?></div>
                  <div class="winzig gedimmt-2"><?= (int) $f['eingaenge'] ?> Eingänge ·
                    <?= count(Util::ausJson((string) $f['felder'], [])) ?> Felder</div>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
      <div class="karte__fuss">
        <span class="klein gedimmt">Formulare fügst du als Baustein auf einer Seite ein.</span>
      </div>
    </div>

    <div class="karte">
      <div class="karte__kopf"><h3>Schnell starten</h3></div>
      <div class="karte__koerper karte__koerper--eng">
        <div class="stapel stapel--eng">
          <?php foreach ([
            ['Newsletter schreiben', 'An Kunden mit Einwilligung', 'newsletter', '/app/kampagne.php?id=neu', 'newsletter.write'],
            ['Automation einrichten', 'Willkommen, Nachfassen, Rückholung', 'automations', '/app/automationen.php', 'automations.write'],
            ['Landingpage erstellen', 'Ein Ziel, kein Menü', 'target', '/app/website.php', 'website.write'],
            ['Rabattcode anlegen', 'Für Aktionen und Empfehlungen', 'percent', '/app/produkte.php', 'products.write'],
          ] as [$name, $text, $icon, $url, $recht]):
            if (!Auth::darf($recht)) { continue; } ?>
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

    <div class="hinweis hinweis--still">
      <?= Icon::svg('shield', 17) ?>
      <div class="hinweis__text">
        <span class="hinweis__titel">Werbung nur mit Einwilligung</span>
        Newsletter und werbliche Automationen gehen ausschließlich an Kunden, bei denen die
        Einwilligung hinterlegt ist. Terminerinnerungen und Rechnungen sind davon nicht
        betroffen – sie gehören zur Leistung.
      </div>
    </div>
  </div>
</div>

<?php if (Auth::darf('marketing.write')): ?>
<dialog class="modal modal--schmal" id="modal-formular">
  <form method="post">
    <?= Auth::csrfFeld() ?>
    <input type="hidden" name="aktion" value="formular">
    <div class="modal__kopf"><h2>Formular anlegen</h2>
      <button type="button" class="rundknopf" data-modal-zu aria-label="Schließen"><?= Icon::svg('x', 17) ?></button></div>
    <div class="modal__koerper">
      <div class="feld"><label class="feld__label" for="fo-name">Name</label>
        <input class="eingabe" id="fo-name" name="name" required placeholder="Kontaktformular"></div>
      <div class="feld"><label class="feld__label" for="fo-best">Text nach dem Absenden</label>
        <input class="eingabe" id="fo-best" name="bestaetigung"
               value="Danke für deine Nachricht! Ich melde mich innerhalb von 24 Stunden."></div>
      <div class="hinweis hinweis--still">
        <?= Icon::svg('info', 16) ?>
        <div class="hinweis__text">Das Formular bekommt Name, E-Mail, Telefon und Nachricht.
          Eingänge landen als Anfrage in der Pipeline.</div>
      </div>
    </div>
    <div class="modal__fuss"><div class="fueller"></div>
      <button type="button" class="btn" data-modal-zu>Abbrechen</button>
      <button type="submit" class="btn btn--primaer">Anlegen</button></div>
  </form>
</dialog>
<?php endif; ?>

<?php require __DIR__ . '/partials/fuss.php'; ?>
