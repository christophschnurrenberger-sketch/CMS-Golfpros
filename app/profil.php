<?php
/**
 * Eigenes Profil – Angaben zur Person, Passwort und Darstellung.
 *
 * Bewusst getrennt von „Team“: Dort verwaltet man andere, hier sich selbst.
 * Jede Rolle darf das eigene Profil ändern, auch die, die sonst nichts in
 * den Einstellungen zu suchen hat.
 */
require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/partials/helfer.php';

if (!Auth::angemeldet()) {
    App::weiter('/login.php');
}

if (App::istPost()) {
    Auth::csrfFordern();
    $ich = Auth::id();

    /* Im Support Mode bleiben Profil und Passwort des Inhabers unangetastet –
       der Support arbeitet mit seinen Rechten, nicht mit seinem Zugang. Eine
       geänderte E-Mail-Adresse wäre sonst der Weg, über „Passwort
       vergessen" das Konto zu übernehmen. */
    if (in_array(App::aktion(), ['passwort', 'profil'], true) && Support::aktiv()) {
        App::melden('Im Support Mode lassen sich Profil und Passwort des Inhabers nicht ändern.', 'fehler');
        App::weiter('/app/profil.php');
    }
    if (App::aktion() === 'profil') {
        $email = strtolower(trim(App::post('email')));
        $belegt = $email !== ''
            ? Tenant::one('users', 'email = :e AND id != :i', ['e' => $email, 'i' => $ich])
            : null;
        if (App::post('name') === '' || $email === '') {
            App::melden('Name und E-Mail werden gebraucht.', 'fehler');
        } elseif ($belegt !== null) {
            App::melden('Diese E-Mail wird bereits verwendet.', 'fehler');
        } else {
            Tenant::update('users', $ich, [
                'name'    => App::post('name'),
                'email'   => $email,
                'titel'   => App::post('titel'),
                'telefon' => App::post('telefon'),
                'bio'     => App::post('bio'),
                'farbe'   => App::post('farbe', '#0d6b4f'),
            ]);
            App::melden('Profil gespeichert.');
        }
    }

    if (App::aktion() === 'passwort') {
        $benutzer = Auth::benutzer();
        $alt      = App::postRoh('alt');
        $neu      = App::postRoh('neu');
        $wdh      = App::postRoh('wiederholung');
        [$okay, $meldung] = Auth::passwortPruefen($neu);

        if ($benutzer === null || !password_verify($alt, (string) $benutzer['passwort'])) {
            App::melden('Das bisherige Passwort stimmt nicht.', 'fehler');
        } elseif (!$okay) {
            App::melden($meldung, 'fehler');
        } elseif ($neu !== $wdh) {
            App::melden('Die beiden neuen Passwörter sind nicht gleich.', 'fehler');
        } else {
            Tenant::update('users', $ich, ['passwort' => Auth::hash($neu)]);
            Audit::schreiben('geaendert', 'user', $ich, 'Passwort geändert');
            App::melden('Passwort geändert.');
        }
    }

    App::weiter('/app/profil.php');
}

$ich = Auth::benutzer() ?? [];

$titel = 'Mein Profil';
$unter = 'Angaben zur Person, Zugang und Darstellung.';
$brotkrumen = [['Einstellungen', '/app/einstellungen.php'], ['Profil', null]];
require __DIR__ . '/partials/kopf.php';
?>

<div class="raster raster--haupt-neben">
  <div>
    <form method="post" class="karte mb-4">
      <?= Auth::csrfFeld() ?>
      <input type="hidden" name="aktion" value="profil">
      <div class="karte__kopf"><h2>Angaben zur Person</h2></div>
      <div class="karte__koerper">
        <div class="feld-reihe feld-reihe--2">
          <div class="feld"><label class="feld__label" for="p-name">Name</label>
            <input class="eingabe" id="p-name" name="name" required
                   value="<?= Util::attr((string) ($ich['name'] ?? '')) ?>"></div>
          <div class="feld"><label class="feld__label" for="p-email">E-Mail</label>
            <input class="eingabe" id="p-email" type="email" name="email" required
                   value="<?= Util::attr((string) ($ich['email'] ?? '')) ?>">
            <div class="feld__hinweis">Damit meldest du dich an.</div></div>
        </div>
        <div class="feld-reihe feld-reihe--2">
          <div class="feld"><label class="feld__label" for="p-titel">Titel</label>
            <input class="eingabe" id="p-titel" name="titel" placeholder="PGA Golf Professional"
                   value="<?= Util::attr((string) ($ich['titel'] ?? '')) ?>">
            <div class="feld__hinweis">Erscheint im Trainerprofil auf der Website.</div></div>
          <div class="feld"><label class="feld__label" for="p-telefon">Telefon</label>
            <input class="eingabe" id="p-telefon" name="telefon"
                   value="<?= Util::attr((string) ($ich['telefon'] ?? '')) ?>"></div>
        </div>
        <div class="feld"><label class="feld__label" for="p-bio">Über mich</label>
          <textarea class="eingabe" id="p-bio" name="bio" rows="4" data-waechst
            placeholder="Ausbildung, Schwerpunkte, Erfahrung – zwei bis vier Sätze reichen."><?= Util::h((string) ($ich['bio'] ?? '')) ?></textarea></div>
        <div class="feld" style="max-width:220px">
          <label class="feld__label" for="p-farbe">Meine Farbe im Kalender</label>
          <input class="eingabe eingabe--farbe" id="p-farbe" type="color" name="farbe"
                 value="<?= Util::attr((string) (($ich['farbe'] ?? '') ?: '#0d6b4f')) ?>"></div>
      </div>
      <div class="karte__fuss"><div class="fueller"></div>
        <button class="btn btn--primaer" type="submit">Speichern</button></div>
    </form>

    <form method="post" class="karte">
      <?= Auth::csrfFeld() ?>
      <input type="hidden" name="aktion" value="passwort">
      <div class="karte__kopf"><h2>Passwort ändern</h2></div>
      <div class="karte__koerper">
        <div class="feld" style="max-width:380px">
          <label class="feld__label" for="p-alt">Bisheriges Passwort</label>
          <input class="eingabe" id="p-alt" type="password" name="alt" autocomplete="current-password" required></div>
        <div class="feld-reihe feld-reihe--2">
          <div class="feld"><label class="feld__label" for="p-neu">Neues Passwort</label>
            <input class="eingabe" id="p-neu" type="password" name="neu" autocomplete="new-password" required>
            <div class="feld__hinweis">Mindestens zehn Zeichen. Eine Wortfolge, die niemand rät,
              ist sicherer als ein kurzes Zeichengemisch.</div></div>
          <div class="feld"><label class="feld__label" for="p-wdh">Wiederholen</label>
            <input class="eingabe" id="p-wdh" type="password" name="wiederholung" autocomplete="new-password" required></div>
        </div>
      </div>
      <div class="karte__fuss"><div class="fueller"></div>
        <button class="btn btn--primaer" type="submit">Passwort ändern</button></div>
    </form>
  </div>

  <div>
    <?= karteAuf('Zugang') ?>
      <div class="karte__koerper">
        <div class="mitte mb-4">
          <?= person((string) ($ich['name'] ?? ''), [
                'unter' => Auth::rollenName(),
                'bild'  => (string) ($ich['bild'] ?? ''),
                'gross' => true,
              ]) ?>
        </div>
        <div class="stapel stapel--eng klein">
          <div class="reihe reihe--zwischen">
            <span class="gedimmt">Rolle</span>
            <span><?= pille(Auth::rollenName(), 'marke') ?></span>
          </div>
          <div class="reihe reihe--zwischen">
            <span class="gedimmt">Workspace</span>
            <span class="halbfett"><?= Util::h(Tenant::name()) ?></span>
          </div>
          <div class="reihe reihe--zwischen">
            <span class="gedimmt">Letzter Login</span>
            <span><?= $ich['letzter_login'] ?? ''
                  ? Util::h(Util::relativ((string) $ich['letzter_login'])) : 'jetzt' ?></span>
          </div>
          <div class="reihe reihe--zwischen">
            <span class="gedimmt">Dabei seit</span>
            <span><?= Util::h(Util::datum((string) ($ich['erstellt'] ?? ''))) ?></span>
          </div>
        </div>
      </div>
      <div class="karte__fuss">
        <div class="fueller"></div>
        <a class="btn btn--klein" href="<?= Util::attr(App::url('/abmelden.php')) ?>">
          <?= Icon::svg('logout', 14) ?> Abmelden</a>
      </div>
    </div>

    <?= karteAuf('Darstellung', '', 'mt-4') ?>
      <div class="karte__koerper">
        <p class="klein gedimmt mb-3">Die Einstellung gilt nur auf diesem Gerät und wird im Browser
          gespeichert – nicht auf dem Server.</p>
        <div class="segment" role="group" aria-label="Farbschema">
          <button type="button" class="segment__teil" data-thema-wert="hell"><?= Icon::svg('sun', 15) ?> Hell</button>
          <button type="button" class="segment__teil" data-thema-wert="dunkel"><?= Icon::svg('moon', 15) ?> Dunkel</button>
          <button type="button" class="segment__teil" data-thema-wert="system"><?= Icon::svg('monitor', 15) ?> System</button>
        </div>
      </div>
    </div>
  </div>
</div>

<?php require __DIR__ . '/partials/fuss.php'; ?>
