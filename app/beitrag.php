<?php
/** Beitrag schreiben – und daraus Social-Media-Beiträge machen. */
require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/partials/helfer.php';

Auth::fordern('modul.content');

$istNeu = App::get('id') === 'neu';
$id = $istNeu ? 0 : App::getInt('id');
$beitrag = $istNeu ? null : Tenant::find('posts', $id);

if (!$istNeu && !$beitrag) {
    App::melden('Dieser Beitrag wurde nicht gefunden.', 'fehler');
    App::weiter('/app/inhalte.php');
}

if (App::istPost()) {
    Auth::csrfFordern();
    Auth::fordern('content.write');
    $aktion = App::aktion();

    if ($aktion === 'speichern') {
        $daten = [
            'titel' => App::post('titel'), 'auszug' => App::post('auszug'),
            'text' => App::postRoh('text'), 'bild' => App::post('bild'),
            'tags' => App::post('tags'), 'status' => App::post('status', 'entwurf'),
            'seo' => Util::json([
                'titel' => App::post('seo_titel'),
                'beschreibung' => App::post('seo_beschreibung'),
            ]),
        ];
        if ($daten['titel'] === '') {
            App::melden('Der Beitrag braucht einen Titel.', 'fehler');
        } else {
            $daten['slug'] = Util::slug(App::post('slug') ?: $daten['titel']);
            if ($daten['status'] === 'veroeffentlicht' && ($beitrag === null || !$beitrag['veroeffentlicht'])) {
                $daten['veroeffentlicht'] = Util::jetzt();
            }
            if ($id > 0) {
                Tenant::update('posts', $id, $daten);
                App::melden('Beitrag gespeichert.');
            } else {
                $daten['user_id'] = Auth::id();
                $id = Tenant::insert('posts', $daten);
                App::melden('Beitrag angelegt.');
            }
            App::weiter('/app/beitrag.php?id=' . $id);
        }
    }

    if ($aktion === 'social' && $id > 0) {
        $texte = KI::socialMedia((string) $beitrag['titel'], (string) $beitrag['text']);
        Tenant::einstellungSetzen('social_' . $id, $texte);
        App::melden('Beiträge für die Kanäle erstellt. Bitte vor dem Posten prüfen.', 'info');
        App::weiter('/app/beitrag.php?id=' . $id . '#social');
    }

    if ($aktion === 'loeschen' && $id > 0) {
        Tenant::delete('posts', $id);
        App::melden('Beitrag gelöscht.');
        App::weiter('/app/inhalte.php');
    }
}

$beitrag = $beitrag ?? ['id' => 0, 'titel' => '', 'slug' => '', 'auszug' => '', 'text' => '',
    'bild' => '', 'tags' => '', 'status' => 'entwurf', 'seo' => '', 'aufrufe' => 0,
    'veroeffentlicht' => null, 'user_id' => Auth::id()];
$seo = Util::ausJson((string) $beitrag['seo'], []);
$social = $id > 0 ? Tenant::einstellung('social_' . $id, []) : [];

$titel = $istNeu ? 'Neuer Beitrag' : (string) $beitrag['titel'];
$brotkrumen = [['Inhalte', '/app/inhalte.php'], [$istNeu ? 'Neu' : Util::kuerzen((string) $beitrag['titel'], 40), null]];
$unter = $istNeu ? '' : ((string) $beitrag['status'] === 'veroeffentlicht'
    ? 'Veröffentlicht am ' . Util::datum((string) $beitrag['veroeffentlicht'])
      . ' · ' . Util::zahl((int) $beitrag['aufrufe']) . ' Aufrufe'
    : 'Entwurf');
require __DIR__ . '/partials/kopf.php';
?>

<form method="post" class="raster raster--haupt-neben">
  <?= Auth::csrfFeld() ?>
  <input type="hidden" name="aktion" value="speichern">

  <div class="karte">
    <div class="karte__koerper">
      <div class="feld">
        <input class="eingabe" name="titel" value="<?= Util::attr((string) $beitrag['titel']) ?>"
               placeholder="Überschrift" required autofocus
               style="font-size:22px;font-weight:650;height:52px;border:0;padding-left:0"
               aria-label="Titel">
      </div>
      <div class="feld">
        <label class="feld__label" for="auszug">Anrisstext</label>
        <textarea class="eingabe" id="auszug" name="auszug" rows="2" data-waechst
          placeholder="Ein Satz, der neugierig macht. Steht in der Übersicht und im Suchergebnis."><?= Util::h((string) $beitrag['auszug']) ?></textarea>
      </div>
      <div class="feld">
        <label class="feld__label" for="text">Text</label>
        <textarea class="eingabe" id="text" name="text" rows="22" data-waechst
          style="font-family:var(--mono);font-size:13.5px;line-height:1.75"
          placeholder="**Fett** mit Sternchen, *kursiv* mit einem Sternchen. Leerzeile trennt Absätze."><?= Util::h((string) $beitrag['text']) ?></textarea>
        <div class="feld__hinweis">Absätze durch Leerzeile trennen. **Fett** und *kursiv* werden übernommen.</div>
      </div>
    </div>
  </div>

  <div class="stapel">
    <div class="karte">
      <div class="karte__kopf"><h3>Veröffentlichung</h3></div>
      <div class="karte__koerper">
        <div class="feld">
          <label class="feld__label" for="status">Status</label>
          <select id="status" name="status">
            <option value="entwurf"<?= (string) $beitrag['status'] === 'entwurf' ? ' selected' : '' ?>>Entwurf</option>
            <option value="veroeffentlicht"<?= (string) $beitrag['status'] === 'veroeffentlicht' ? ' selected' : '' ?>>Veröffentlicht</option>
          </select>
        </div>
        <div class="feld">
          <label class="feld__label" for="slug">Adresse</label>
          <div class="eingabe-gruppe">
            <span class="eingabe-gruppe__anhang eingabe-gruppe__anhang--vorn">/blog/</span>
            <input class="eingabe" id="slug" name="slug" value="<?= Util::attr((string) $beitrag['slug']) ?>">
          </div>
        </div>
        <div class="feld">
          <label class="feld__label" for="bild">Titelbild</label>
          <input class="eingabe" id="bild" name="bild" value="<?= Util::attr((string) $beitrag['bild']) ?>"
                 placeholder="uploads/…">
          <div class="feld__hinweis">
            <a href="<?= Util::attr(App::url('/app/medien.php')) ?>" target="_blank" rel="noopener">Mediathek</a>
          </div>
        </div>
        <div class="feld">
          <label class="feld__label" for="tags">Schlagworte</label>
          <input class="eingabe" id="tags" name="tags" value="<?= Util::attr((string) $beitrag['tags']) ?>"
                 placeholder="Training, Putten">
        </div>
      </div>
      <div class="karte__fuss">
        <div class="fueller"></div>
        <button class="btn btn--primaer" type="submit">Speichern</button>
      </div>
    </div>

    <div class="karte">
      <div class="karte__kopf"><h3>Für Suchmaschinen</h3></div>
      <div class="karte__koerper">
        <div class="feld">
          <label class="feld__label" for="seo_titel">Titel</label>
          <input class="eingabe" id="seo_titel" name="seo_titel" maxlength="70"
                 value="<?= Util::attr((string) ($seo['titel'] ?? '')) ?>" data-zaehler="z1">
          <div class="feld__hinweis" id="z1"></div>
        </div>
        <div class="feld">
          <label class="feld__label" for="seo_beschreibung">Beschreibung</label>
          <textarea class="eingabe" id="seo_beschreibung" name="seo_beschreibung" rows="3" maxlength="170"
                    data-zaehler="z2" data-waechst><?= Util::h((string) ($seo['beschreibung'] ?? '')) ?></textarea>
          <div class="feld__hinweis" id="z2"></div>
        </div>
      </div>
    </div>
  </div>
</form>

<?php if (!$istNeu): ?>
<div class="mt-6" id="social">
  <div class="karte">
    <div class="karte__kopf">
      <h2><?= Icon::svg('ai', 17) ?> Aus dem Beitrag Beiträge machen</h2>
      <div class="fueller"></div>
      <?php if (Auth::darf('content.write')): ?>
        <form method="post">
          <?= Auth::csrfFeld() ?>
          <input type="hidden" name="aktion" value="social">
          <button class="btn btn--klein btn--primaer" type="submit">
            <?= Icon::svg('wand', 14) ?> <?= $social === [] ? 'Erstellen' : 'Neu erstellen' ?></button>
        </form>
      <?php endif; ?>
    </div>
    <div class="karte__koerper">
      <?php if ($social === []): ?>
        <?= leerzustand('marketing', 'Ein Text, sechs Kanäle',
              'Aus diesem Beitrag entstehen Entwürfe für Instagram, Facebook, LinkedIn, den '
              . 'Newsletter, eine YouTube-Beschreibung und ein Kurzvideo-Skript. '
              . 'Alles Entwürfe – du entscheidest, was davon rausgeht.', []) ?>
      <?php else: ?>
        <div class="raster raster--2">
          <?php foreach ([
            'instagram' => ['Instagram', 'community'],
            'facebook' => ['Facebook', 'community'],
            'linkedin' => ['LinkedIn', 'building'],
            'newsletter' => ['Newsletter', 'newsletter'],
            'youtube' => ['YouTube-Beschreibung', 'video'],
            'kurzvideo' => ['Kurzvideo-Skript', 'play'],
          ] as $key => [$name, $icon]):
            if (empty($social[$key])) { continue; } ?>
            <div class="karte karte--flach">
              <div class="karte__kopf">
                <h3><?= Icon::svg($icon, 15) ?> <?= Util::h($name) ?></h3>
                <div class="fueller"></div>
                <button class="btn btn--klein btn--geist" data-kopieren="<?= Util::attr((string) $social[$key]) ?>">
                  <?= Icon::svg('copy', 14) ?></button>
              </div>
              <div class="karte__koerper karte__koerper--eng">
                <div class="klein" style="white-space:pre-wrap;line-height:1.65;color:var(--text-2)"><?= Util::h((string) $social[$key]) ?></div>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
        <div class="ki-warnung mt-4"><?= Icon::svg('info', 13) ?>
          Entwürfe. Vor dem Posten lesen – und die Stimme so anpassen, dass sie nach dir klingt.</div>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php if (Auth::darf('content.write')): ?>
<form method="post" class="mt-4" data-bestaetigen="Diesen Beitrag löschen?">
  <?= Auth::csrfFeld() ?>
  <input type="hidden" name="aktion" value="loeschen">
  <button class="btn" type="submit" style="color:var(--gefahr)">
    <?= Icon::svg('trash', 15) ?> Beitrag löschen</button>
</form>
<?php endif; ?>
<?php endif; ?>

<?php require __DIR__ . '/partials/fuss.php'; ?>
