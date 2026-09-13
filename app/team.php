<?php
/**
 * Team, Rollen und Rechte.
 *
 * Die Rechtevergabe ist bewusst rollenbasiert und nicht als Kreuztabelle
 * mit 60 Häkchen gebaut: Wer ein Team führt, denkt in Aufgaben („macht die
 * Buchhaltung“), nicht in Berechtigungsnamen. Was eine Rolle darf, steht
 * trotzdem offen sichtbar unter der Liste – niemand soll raten müssen.
 */
require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/partials/helfer.php';

Auth::fordern('modul.settings');
Auth::fordern('settings.team');

$orte = Tenant::all('locations', 'aktiv = 1', [], 'name');

if (App::istPost()) {
    Auth::csrfFordern();
    $id     = App::postInt('id');
    $aktion = App::aktion();

    if ($aktion === 'speichern') {
        $email  = strtolower(trim(App::post('email')));
        $rolle  = App::post('rolle', 'trainer');
        if (!isset(Auth::ROLLEN[$rolle])) {
            $rolle = 'trainer';
        }
        /*
         * Nur der Inhaber darf die Inhaberrolle vergeben. Sonst könnte eine
         * Administration sich selbst zum Inhaber machen und den Workspace
         * übernehmen.
         */
        if ($rolle === 'owner' && !Auth::istInhaber()) {
            $rolle = 'admin';
        }

        $daten = [
            'name'      => App::post('name'),
            'email'     => $email,
            'rolle'     => $rolle,
            'titel'     => App::post('titel'),
            'telefon'   => App::post('telefon'),
            'bio'       => App::post('bio'),
            'farbe'     => App::post('farbe', '#0d6b4f'),
            'standorte' => Util::json(array_map('intval', App::postListe('standorte'))),
            'aktiv'     => App::postBool('aktiv') ? 1 : 0,
        ];

        $vorhanden = $email !== ''
            ? Tenant::one('users', 'email = :e AND id != :i', ['e' => $email, 'i' => $id])
            : null;

        if ($daten['name'] === '' || $email === '') {
            App::melden('Name und E-Mail werden gebraucht.', 'fehler');
        } elseif ($vorhanden !== null) {
            App::melden('Diese E-Mail gehört schon zu einem anderen Teammitglied.', 'fehler');
        } elseif ($id > 0) {
            $alt = Tenant::find('users', $id);
            // Der letzte aktive Inhaber darf sich nicht selbst aussperren.
            if ($alt !== null && (string) $alt['rolle'] === 'owner' && $rolle !== 'owner'
                && Tenant::count('users', "rolle = 'owner' AND aktiv = 1") <= 1) {
                App::melden('Es muss mindestens einen Inhaber geben.', 'fehler');
            } else {
                Tenant::update('users', $id, $daten);
                Audit::schreiben('geaendert', 'user', $id, $daten['name']);
                App::melden('Teammitglied gespeichert.');
            }
        } else {
            /*
             * Eingeladene bekommen kein Passwort, sondern einen Reset-Token:
             * Das Passwort kennt danach nur die Person selbst.
             */
            $daten['passwort']    = Auth::hash(Util::token(12));
            $daten['reset_token'] = Util::token(24);
            $daten['reset_bis']   = date('Y-m-d H:i:s', time() + 7 * 86400);
            $daten['erstellt']    = Util::jetzt();
            $neu = Tenant::insert('users', $daten);
            Audit::schreiben('erstellt', 'user', $neu, $daten['name']);

            $link = App::absolut('/login.php?token=' . $daten['reset_token']);
            Mail::senden($email, 'Dein Zugang zu ' . Tenant::name(),
                "Hallo " . $daten['name'] . ",\n\n"
                . "du wurdest zu " . Tenant::name() . " eingeladen. Über diesen Link "
                . "vergibst du dein Passwort:\n\n" . $link . "\n\n"
                . "Der Link gilt sieben Tage.");
            App::melden('Einladung an ' . $email . ' verschickt.');
        }
    }

    if ($aktion === 'status' && $id > 0) {
        $u = Tenant::find('users', $id);
        if ($u !== null) {
            if ($id === Auth::id()) {
                App::melden('Den eigenen Zugang kann man nicht deaktivieren.', 'fehler');
            } elseif ((string) $u['rolle'] === 'owner' && Tenant::count('users', "rolle = 'owner' AND aktiv = 1") <= 1) {
                App::melden('Es muss mindestens einen aktiven Inhaber geben.', 'fehler');
            } else {
                $neu = (int) $u['aktiv'] === 1 ? 0 : 1;
                Tenant::update('users', $id, ['aktiv' => $neu]);
                Audit::schreiben('geaendert', 'user', $id, $neu === 1 ? 'aktiviert' : 'deaktiviert');
                App::melden($neu === 1 ? 'Zugang wieder aktiv.' : 'Zugang deaktiviert. Termine und Historie bleiben erhalten.');
            }
        }
    }

    if ($aktion === 'einladung' && $id > 0) {
        $u = Tenant::find('users', $id);
        if ($u !== null) {
            $token = Util::token(24);
            Tenant::update('users', $id, [
                'reset_token' => $token,
                'reset_bis'   => date('Y-m-d H:i:s', time() + 7 * 86400),
            ]);
            Mail::senden((string) $u['email'], 'Neuer Zugangslink für ' . Tenant::name(),
                "Hallo " . $u['name'] . ",\n\nhier ist dein neuer Link zum Setzen des Passworts:\n\n"
                . App::absolut('/login.php?token=' . $token) . "\n\nDer Link gilt sieben Tage.");
            App::melden('Neuer Zugangslink verschickt.');
        }
    }

    App::weiter('/app/team.php');
}

$team = Tenant::all('users', '', [], 'aktiv DESC, rolle, name');

/* Wie viele Personen der Tarif erlaubt – begrenzt, aber nie mitten im Betrieb. */
$grenze = match (Tenant::plan()) {
    'starter' => 1,
    'pro'     => 3,
    'business'=> 8,
    default   => 0,          // 0 = unbegrenzt
};
$aktive = count(array_filter($team, static fn ($u) => (int) $u['aktiv'] === 1));
$voll   = $grenze > 0 && $aktive >= $grenze;

$titel = 'Team';
$unter = 'Wer arbeitet mit – und wer darf was sehen.';
$brotkrumen = [['Einstellungen', '/app/einstellungen.php'], ['Team', null]];
$aktionen = $voll
    ? '<a class="btn" href="' . Util::attr(App::url('/app/tarif.php')) . '">' . Icon::svg('layers', 15) . ' Tarif erweitern</a>'
    : '<button class="btn btn--primaer" data-modal-auf="modal-person" data-modal-titel="Person einladen">'
      . Icon::svg('plus', 15) . ' Person einladen</button>';
require __DIR__ . '/partials/kopf.php';
?>

<?php if ($grenze > 0): ?>
  <div class="hinweis <?= $voll ? 'hinweis--warnung' : 'hinweis--still' ?> mb-4">
    <?= Icon::svg($voll ? 'alert' : 'info', 17) ?>
    <div class="hinweis__text">
      <?php if ($voll): ?>
        Der Tarif <?= Util::h(Module::planName(Tenant::plan())) ?> umfasst
        <?= $grenze ?> <?= $grenze === 1 ? 'Person' : 'Personen' ?>. Für weitere Trainer
        <a href="<?= Util::attr(App::url('/app/tarif.php')) ?>">den Tarif wechseln</a>.
      <?php else: ?>
        <?= $aktive ?> von <?= $grenze ?> Plätzen im Tarif
        <?= Util::h(Module::planName(Tenant::plan())) ?> belegt.
      <?php endif; ?>
    </div>
  </div>
<?php endif; ?>

<div class="karte mb-5">
  <div class="tabelle-huelle">
    <table class="tabelle">
      <thead><tr>
        <th>Person</th><th>Rolle</th><th class="nicht-mobil">Standorte</th>
        <th class="nicht-mobil">Letzter Login</th><th>Status</th><th></th>
      </tr></thead>
      <tbody>
      <?php foreach ($team as $u):
        $eigen  = (int) $u['id'] === Auth::id();
        $ortIds = Util::ausJson((string) $u['standorte'], []);
        $ortNamen = [];
        foreach ($orte as $o) {
            if (in_array((int) $o['id'], array_map('intval', $ortIds), true)) {
                $ortNamen[] = (string) $o['name'];
            }
        } ?>
        <tr<?= (int) $u['aktiv'] === 0 ? ' style="opacity:.55"' : '' ?>>
          <td>
            <?= person((string) $u['name'], [
                  'unter' => (string) ($u['titel'] ?: $u['email']),
                  'bild'  => (string) $u['bild'],
                ]) ?>
            <?php if ($eigen): ?> <?= pille('du', 'marke') ?><?php endif; ?>
          </td>
          <td><?= pille(Auth::rollenName((string) $u['rolle']), (string) $u['rolle'] === 'owner' ? 'marke' : '') ?></td>
          <td class="nicht-mobil klein gedimmt">
            <?= $ortNamen === [] ? 'alle' : Util::h(implode(', ', $ortNamen)) ?>
          </td>
          <td class="nicht-mobil klein gedimmt">
            <?= $u['letzter_login'] ? Util::h(Util::relativ((string) $u['letzter_login'])) : 'noch nie' ?>
          </td>
          <td>
            <?= (int) $u['aktiv'] === 1
                ? pille('aktiv', 'erfolg', true)
                : pille('deaktiviert', '', true) ?>
          </td>
          <td class="rechts">
            <div class="reihe reihe--eng reihe--rechts">
              <button class="btn btn--klein" data-modal-auf="modal-person"
                      data-modal-titel="Teammitglied bearbeiten"
                      data-setzid="<?= (int) $u['id'] ?>"
                      data-setzname="<?= Util::attr((string) $u['name']) ?>"
                      data-setzemail="<?= Util::attr((string) $u['email']) ?>"
                      data-setzrolle="<?= Util::attr((string) $u['rolle']) ?>"
                      data-setztitel="<?= Util::attr((string) $u['titel']) ?>"
                      data-setztelefon="<?= Util::attr((string) $u['telefon']) ?>"
                      data-setzbio="<?= Util::attr((string) $u['bio']) ?>"
                      data-setzfarbe="<?= Util::attr((string) ($u['farbe'] ?: '#0d6b4f')) ?>">
                <?= Icon::svg('edit', 14) ?> Bearbeiten</button>
              <?php if (!$eigen): ?>
                <form method="post" class="inline">
                  <?= Auth::csrfFeld() ?>
                  <input type="hidden" name="aktion" value="status">
                  <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
                  <button class="btn btn--klein" type="submit">
                    <?= (int) $u['aktiv'] === 1 ? 'Deaktivieren' : 'Aktivieren' ?></button>
                </form>
              <?php endif; ?>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?= karteAuf('Was die Rollen dürfen') ?>
  <div class="karte__koerper">
    <p class="gedimmt mb-4">Eine Rolle gibt genau die Bereiche frei, die für diese
      Aufgabe gebraucht werden. Was nicht freigegeben ist, taucht im Menü gar nicht erst auf –
      niemand klickt auf eine Sperre.</p>
    <div class="raster raster--2">
      <?php foreach ([
        'owner'      => ['Alles, einschließlich Tarif, Team und Löschung des Workspace.', 'shield'],
        'admin'      => ['Alle Bereiche außer Tarif und Abrechnung des Workspace.', 'settings'],
        'head_pro'   => ['Training, Kunden, Kalender, Team und Auswertung – ohne Finanzen.', 'award'],
        'trainer'    => ['Eigene Termine, Kunden, Trainingspläne und Videoanalysen.', 'training'],
        'assistant'  => ['Kunden, Leads, Kalender und Buchungen – ohne Preise und Finanzen.', 'customers'],
        'accountant' => ['Rechnungen, Zahlungen, Produkte und Auswertung – keine Trainingsdaten.', 'invoices'],
        'marketing'  => ['Website, Inhalte, Newsletter, Kampagnen und Leads.', 'marketing'],
      ] as $rolle => [$text, $icon]): ?>
        <div class="reihe reihe--oben" style="gap:12px">
          <span class="kachel-link__symbol"><?= Icon::svg($icon, 16) ?></span>
          <div>
            <div class="halbfett"><?= Util::h(Auth::rollenName($rolle)) ?></div>
            <div class="klein gedimmt"><?= Util::h($text) ?></div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<dialog class="modal modal--breit" id="modal-person">
  <form method="post">
    <?= Auth::csrfFeld() ?>
    <input type="hidden" name="aktion" value="speichern">
    <input type="hidden" name="id" value="0">
    <div class="modal__kopf"><h2>Teammitglied</h2>
      <button type="button" class="rundknopf" data-modal-zu aria-label="Schließen"><?= Icon::svg('x', 17) ?></button></div>
    <div class="modal__koerper">
      <div class="feld-reihe feld-reihe--2">
        <div class="feld"><label class="feld__label" for="t-name">Name</label>
          <input class="eingabe" id="t-name" name="name" required placeholder="Julia Brenner"></div>
        <div class="feld"><label class="feld__label" for="t-email">E-Mail</label>
          <input class="eingabe" id="t-email" type="email" name="email" required>
          <div class="feld__hinweis">Damit meldet sich die Person an.</div></div>
      </div>
      <div class="feld-reihe feld-reihe--2">
        <div class="feld"><label class="feld__label" for="t-rolle">Rolle</label>
          <select id="t-rolle" name="rolle">
            <?php foreach (Auth::ROLLEN as $k => $v):
              if ($k === 'owner' && !Auth::istInhaber()) { continue; } ?>
              <option value="<?= $k ?>"<?= $k === 'trainer' ? ' selected' : '' ?>><?= Util::h($v) ?></option>
            <?php endforeach; ?>
          </select></div>
        <div class="feld"><label class="feld__label" for="t-titel">Titel</label>
          <input class="eingabe" id="t-titel" name="titel" placeholder="PGA Golf Professional">
          <div class="feld__hinweis">Erscheint auf der Website im Trainerprofil.</div></div>
      </div>
      <div class="feld-reihe feld-reihe--2">
        <div class="feld"><label class="feld__label" for="t-telefon">Telefon</label>
          <input class="eingabe" id="t-telefon" name="telefon"></div>
        <div class="feld"><label class="feld__label" for="t-farbe">Farbe im Kalender</label>
          <input class="eingabe eingabe--farbe" id="t-farbe" type="color" name="farbe" value="#0d6b4f"></div>
      </div>
      <div class="feld"><label class="feld__label" for="t-bio">Kurzprofil</label>
        <textarea class="eingabe" id="t-bio" name="bio" rows="3" data-waechst
                  placeholder="Schwerpunkte, Ausbildung, Erfahrung – erscheint auf der Website."></textarea></div>
      <?php if (count($orte) > 1): ?>
        <div class="feld">
          <span class="feld__label">Standorte</span>
          <div class="stapel stapel--eng">
            <?php foreach ($orte as $o): ?>
              <label class="haken"><input type="checkbox" name="standorte[]" value="<?= (int) $o['id'] ?>">
                <span class="haken__text"><?= Util::h((string) $o['name']) ?></span></label>
            <?php endforeach; ?>
          </div>
          <div class="feld__hinweis">Nichts ausgewählt heißt: an allen Standorten im Einsatz.</div>
        </div>
      <?php endif; ?>
      <label class="haken"><input type="checkbox" name="aktiv" value="1" checked>
        <span class="haken__text">Zugang aktiv</span></label>
    </div>
    <div class="modal__fuss"><div class="fueller"></div>
      <button type="button" class="btn" data-modal-zu>Abbrechen</button>
      <button type="submit" class="btn btn--primaer">Speichern</button></div>
  </form>
</dialog>

<?php require __DIR__ . '/partials/fuss.php'; ?>
