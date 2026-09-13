<?php
/** Community: Gruppen, Beiträge, Challenges, Rangliste. */
require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/partials/helfer.php';

Auth::fordern('modul.community');

if (App::istPost()) {
    Auth::csrfFordern();
    Auth::fordern('community.write');
    $aktion = App::aktion();

    if ($aktion === 'beitrag') {
        Community::beitragSchreiben(App::postInt('group_id'), App::postRoh('text'), 'user', Auth::id());
        App::melden('Beitrag veröffentlicht.');
    }
    if ($aktion === 'gruppe') {
        Tenant::insert('community_groups', [
            'name' => App::post('name') ?: 'Neue Gruppe',
            'beschreibung' => App::post('beschreibung'),
            'privat' => App::postBool('privat') ? 1 : 0,
        ]);
        App::melden('Gruppe angelegt.');
    }
    if ($aktion === 'challenge') {
        Tenant::insert('challenges', [
            'titel' => App::post('titel'), 'beschreibung' => App::post('beschreibung'),
            'symbol' => App::post('symbol', '🎯'),
            'ziel_art' => App::post('ziel_art', 'einheiten'),
            'ziel_wert' => max(1, App::postInt('ziel_wert', 7)),
            'start' => App::post('start', Util::heute()),
            'ende' => App::post('ende', date('Y-m-d', strtotime('+30 days'))),
            'xp' => max(10, App::postInt('xp', 150)), 'aktiv' => 1,
        ]);
        App::melden('Challenge angelegt.');
    }
    if ($aktion === 'beitrag_weg') {
        Tenant::delete('community_posts', App::postInt('post_id'));
        App::melden('Beitrag entfernt.');
    }
    App::weiter('/app/community.php');
}

$gruppeId = App::getInt('gruppe');
$gruppen = Community::gruppen();
$beitraege = Community::beitraege($gruppeId, 40);
$challenges = Community::challenges(true);
$rangliste = Gamification::rangliste(10);

$titel = 'Community';
$unter = count($gruppen) . ' Gruppen · ' . Tenant::count('community_posts') . ' Beiträge · '
       . count($challenges) . ' laufende Challenges';
$aktionen = Auth::darf('community.write')
    ? '<button class="btn" data-modal-auf="modal-challenge">' . Icon::svg('flag', 15) . ' Challenge</button>'
      . '<button class="btn btn--primaer" data-modal-auf="modal-beitrag">'
      . Icon::svg('plus', 15) . ' Beitrag schreiben</button>' : '';
require __DIR__ . '/partials/kopf.php';
?>

<?php if ($gruppen === []): ?>
  <div class="karte"><div class="karte__koerper">
    <?= leerzustand('community', 'Noch keine Community',
          'Eine Gruppe lebt nicht von Funktionen, sondern davon, dass jemand sie betreut. '
          . 'Fang klein an: eine Gruppe für alle, eine für den laufenden Platzreifekurs, '
          . 'und eine Challenge, die in zwei Wochen zu schaffen ist.', []) ?>
    <?php if (Auth::darf('community.write')): ?>
      <div class="mitte"><button class="btn btn--primaer" data-modal-auf="modal-gruppe">
        <?= Icon::svg('plus', 15) ?> Erste Gruppe anlegen</button></div>
    <?php endif; ?>
  </div></div>
<?php else: ?>

<div class="raster raster--haupt-neben">
  <div class="stapel">
    <div class="reihe reihe--eng reihe--umbruch">
      <a class="pille <?= $gruppeId === 0 ? 'pille--marke' : 'pille--offen' ?>"
         href="<?= Util::attr(App::url('/app/community.php')) ?>">Alle Gruppen</a>
      <?php foreach ($gruppen as $g): ?>
        <a class="pille <?= $gruppeId === (int) $g['id'] ? 'pille--marke' : 'pille--offen' ?>"
           href="<?= Util::attr(App::url('/app/community.php?gruppe=' . (int) $g['id'])) ?>">
          <?= Util::h((string) $g['name']) ?></a>
      <?php endforeach; ?>
      <?php if (Auth::darf('community.write')): ?>
        <button class="pille pille--offen" data-modal-auf="modal-gruppe" style="cursor:pointer;border:1px dashed var(--rand-2)">
          <?= Icon::svg('plus', 12) ?> Gruppe</button>
      <?php endif; ?>
    </div>

    <?php if ($beitraege === []): ?>
      <div class="karte"><div class="karte__koerper">
        <?= leerzustand('message', 'Noch keine Beiträge',
              'Der erste Beitrag kommt am besten von dir: eine Ankündigung, ein Tipp, ein Foto '
              . 'von der Range. Danach trauen sich die anderen.', []) ?>
      </div></div>
    <?php else: ?>
      <?php foreach ($beitraege as $b):
        $autor = Community::autorName($b); ?>
        <div class="karte">
          <div class="karte__koerper">
            <div class="reihe reihe--eng mb-3">
              <?= person($autor, [
                    'unter' => (string) $b['autor_typ'] === 'user' ? 'Trainer' : 'Kunde',
                  ]) ?>
              <div class="fueller"></div>
              <?php if ((int) $b['angeheftet'] === 1): ?><?= pille('angeheftet', 'akzent') ?><?php endif; ?>
              <span class="winzig gedimmt-2"><?= Util::h(Util::relativ((string) $b['erstellt'])) ?></span>
              <?php if (Auth::darf('community.write')): ?>
                <form method="post" data-bestaetigen="Beitrag entfernen?">
                  <?= Auth::csrfFeld() ?>
                  <input type="hidden" name="aktion" value="beitrag_weg">
                  <input type="hidden" name="post_id" value="<?= (int) $b['id'] ?>">
                  <button class="btn btn--klein btn--geist btn--nur-symbol" type="submit"
                          aria-label="Entfernen"><?= Icon::svg('trash', 13) ?></button>
                </form>
              <?php endif; ?>
            </div>
            <div style="font-size:14.5px;line-height:1.7"><?= nl2br(Util::h((string) $b['text'])) ?></div>
            <div class="reihe reihe--eng mt-4 klein gedimmt">
              <?= Icon::svg('heart', 15) ?> <?= (int) $b['likes'] ?>
              <span style="margin-left:var(--r3)"><?= Icon::svg('message', 15) ?> <?= (int) $b['kommentare'] ?></span>
            </div>
            <?php $kommentare = Community::kommentare((int) $b['id']);
            if ($kommentare !== []): ?>
              <div class="mt-4" style="border-top:1px solid var(--rand);padding-top:var(--r3)">
                <?php foreach ($kommentare as $k): ?>
                  <div class="reihe reihe--eng mb-3" style="align-items:flex-start">
                    <?= person(Community::autorName($k)) ?>
                    <div style="flex:1;min-width:0;font-size:13.5px"><?= Util::h((string) $k['text']) ?></div>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>

  <div class="stapel">
    <div class="karte">
      <div class="karte__kopf"><h3>Challenges</h3>
        <div class="fueller"></div>
        <?php if (Auth::darf('community.write')): ?>
          <button class="btn btn--klein btn--geist" data-modal-auf="modal-challenge"><?= Icon::svg('plus', 14) ?></button>
        <?php endif; ?>
      </div>
      <div class="karte__koerper karte__koerper--eng">
        <?php if ($challenges === []): ?>
          <p class="klein gedimmt mitte" style="padding:var(--r3) 0">Keine laufende Challenge.</p>
        <?php else: ?>
          <div class="stapel stapel--eng">
            <?php foreach ($challenges as $c):
              $teilnehmer = Tenant::count('challenge_participants', 'challenge_id = :c', ['c' => (int) $c['id']]);
              $fertig = Tenant::count('challenge_participants',
                'challenge_id = :c AND abgeschlossen IS NOT NULL', ['c' => (int) $c['id']]); ?>
              <div style="padding:var(--r3);border:1px solid var(--rand);border-radius:var(--radius)">
                <div class="reihe reihe--eng mb-2">
                  <span style="font-size:18px"><?= Util::h((string) $c['symbol']) ?></span>
                  <span class="halbfett" style="font-size:13.5px"><?= Util::h((string) $c['titel']) ?></span>
                  <div class="fueller"></div>
                  <span class="pille pille--akzent"><?= (int) $c['xp'] ?> XP</span>
                </div>
                <div class="klein gedimmt"><?= Util::h((string) $c['beschreibung']) ?></div>
                <div class="reihe reihe--eng mt-3 winzig gedimmt-2">
                  <?= Icon::svg('customers', 13) ?> <?= $teilnehmer ?> dabei
                  <span>·</span>
                  <?= Icon::svg('check', 13) ?> <?= $fertig ?> geschafft
                  <?php if ((string) $c['ende'] !== ''): ?>
                    <span>·</span> bis <?= Util::h(Util::datum((string) $c['ende'], false)) ?>
                  <?php endif; ?>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <div class="karte">
      <div class="karte__kopf"><h3>Rangliste</h3>
        <div class="fueller"></div><span class="klein gedimmt">Punkte</span></div>
      <div class="karte__koerper karte__koerper--eng">
        <?php if ($rangliste === []): ?>
          <p class="klein gedimmt mitte" style="padding:var(--r3) 0">Noch keine Punkte vergeben.</p>
        <?php else: ?>
          <div class="stapel stapel--eng">
            <?php foreach ($rangliste as $i => $r): ?>
              <a class="reihe" href="<?= Util::attr(App::url('/app/kunde.php?id=' . (int) $r['customer_id'])) ?>"
                 style="padding:6px;border-radius:8px;color:inherit">
                <span class="gedimmt-2 mono" style="width:22px;text-align:right"><?= $i + 1 ?></span>
                <?= person(trim($r['vorname'] . ' ' . $r['nachname']),
                      ['unter' => 'Stufe ' . (int) $r['level']
                        . ((int) $r['streak'] > 1 ? ' · ' . (int) $r['streak'] . ' Tage in Folge' : '')]) ?>
                <div class="fueller"></div>
                <span class="halbfett tabnum klein"><?= Util::zahl((int) $r['xp']) ?></span>
              </a>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <div class="hinweis hinweis--still">
      <?= Icon::svg('info', 17) ?>
      <div class="hinweis__text">
        <span class="hinweis__titel">Punkte gibt es für Training</span>
        Buchen, Erscheinen, Runde erfassen, Kurs abschließen – nicht fürs Einloggen.
        Ein Belohnungssystem, das Anwesenheit statt Fortschritt belohnt, verliert binnen
        Wochen seine Glaubwürdigkeit.
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<?php if (Auth::darf('community.write')): ?>
<dialog class="modal" id="modal-beitrag">
  <form method="post">
    <?= Auth::csrfFeld() ?>
    <input type="hidden" name="aktion" value="beitrag">
    <div class="modal__kopf"><h2>Beitrag schreiben</h2>
      <button type="button" class="rundknopf" data-modal-zu aria-label="Schließen"><?= Icon::svg('x', 17) ?></button></div>
    <div class="modal__koerper">
      <?php if ($gruppen !== []): ?>
        <div class="feld"><label class="feld__label" for="cb-gruppe">Gruppe</label>
          <select id="cb-gruppe" name="group_id">
            <?php foreach ($gruppen as $g): ?>
              <option value="<?= (int) $g['id'] ?>"<?= $gruppeId === (int) $g['id'] ? ' selected' : '' ?>>
                <?= Util::h((string) $g['name']) ?></option>
            <?php endforeach; ?>
          </select></div>
      <?php endif; ?>
      <div class="feld"><label class="feld__label" for="cb-text">Beitrag</label>
        <textarea class="eingabe" id="cb-text" name="text" rows="5" data-waechst required autofocus
          placeholder="Die Range ist ab Montag wieder komplett geöffnet."></textarea></div>
    </div>
    <div class="modal__fuss"><div class="fueller"></div>
      <button type="button" class="btn" data-modal-zu>Abbrechen</button>
      <button type="submit" class="btn btn--primaer">Veröffentlichen</button></div>
  </form>
</dialog>

<dialog class="modal modal--schmal" id="modal-gruppe">
  <form method="post">
    <?= Auth::csrfFeld() ?>
    <input type="hidden" name="aktion" value="gruppe">
    <div class="modal__kopf"><h2>Gruppe anlegen</h2>
      <button type="button" class="rundknopf" data-modal-zu aria-label="Schließen"><?= Icon::svg('x', 17) ?></button></div>
    <div class="modal__koerper">
      <div class="feld"><label class="feld__label" for="cg-name">Name</label>
        <input class="eingabe" id="cg-name" name="name" required placeholder="Platzreife-Gruppe Frühjahr"></div>
      <div class="feld"><label class="feld__label" for="cg-text">Beschreibung</label>
        <input class="eingabe" id="cg-text" name="beschreibung" placeholder="Fragen, Termine, gegenseitige Motivation"></div>
      <label class="haken"><input type="checkbox" name="privat" value="1">
        <span class="haken__text">Private Gruppe</span></label>
    </div>
    <div class="modal__fuss"><div class="fueller"></div>
      <button type="button" class="btn" data-modal-zu>Abbrechen</button>
      <button type="submit" class="btn btn--primaer">Anlegen</button></div>
  </form>
</dialog>

<dialog class="modal" id="modal-challenge">
  <form method="post">
    <?= Auth::csrfFeld() ?>
    <input type="hidden" name="aktion" value="challenge">
    <div class="modal__kopf"><h2>Challenge anlegen</h2>
      <button type="button" class="rundknopf" data-modal-zu aria-label="Schließen"><?= Icon::svg('x', 17) ?></button></div>
    <div class="modal__koerper">
      <div class="feld-reihe feld-reihe--2">
        <div class="feld"><label class="feld__label" for="ch-titel">Titel</label>
          <input class="eingabe" id="ch-titel" name="titel" required placeholder="7 Tage Putting"></div>
        <div class="feld"><label class="feld__label" for="ch-symbol">Symbol</label>
          <input class="eingabe" id="ch-symbol" name="symbol" value="🎯" maxlength="4"></div>
      </div>
      <div class="feld"><label class="feld__label" for="ch-text">Beschreibung</label>
        <input class="eingabe" id="ch-text" name="beschreibung"
               placeholder="Sieben Tage in Folge zehn Minuten putten."></div>
      <div class="feld-reihe feld-reihe--3">
        <div class="feld"><label class="feld__label" for="ch-art">Zähleinheit</label>
          <select id="ch-art" name="ziel_art">
            <option value="tage">Tage</option><option value="einheiten">Einheiten</option>
            <option value="runden">Runden</option>
          </select></div>
        <div class="feld"><label class="feld__label" for="ch-wert">Ziel</label>
          <input class="eingabe" id="ch-wert" type="number" name="ziel_wert" value="7" min="1"></div>
        <div class="feld"><label class="feld__label" for="ch-xp">Punkte</label>
          <input class="eingabe" id="ch-xp" type="number" name="xp" value="150" min="10"></div>
      </div>
      <div class="feld-reihe feld-reihe--2">
        <div class="feld"><label class="feld__label" for="ch-start">Beginn</label>
          <input class="eingabe" id="ch-start" type="date" name="start" value="<?= Util::attr(Util::heute()) ?>"></div>
        <div class="feld"><label class="feld__label" for="ch-ende">Ende</label>
          <input class="eingabe" id="ch-ende" type="date" name="ende"
                 value="<?= Util::attr(date('Y-m-d', strtotime('+30 days'))) ?>"></div>
      </div>
    </div>
    <div class="modal__fuss"><div class="fueller"></div>
      <button type="button" class="btn" data-modal-zu>Abbrechen</button>
      <button type="submit" class="btn btn--primaer">Anlegen</button></div>
  </form>
</dialog>
<?php endif; ?>

<?php require __DIR__ . '/partials/fuss.php'; ?>
