<?php
/**
 * Einstellungen der Betreiberzentrale und die Betreiber selbst.
 */
require __DIR__ . '/partials/start.php';
Betreiber::fordern('einstellungen.write');

if (App::istPost()) {
    Auth::csrfFordern();
    $aktion = App::aktion();

    if ($aktion === 'speichern') {
        $vorher = Plattform::alle();
        $aktivTage = App::postInt('aktiv_tage', 7);
        $inaktivTage = App::postInt('inaktiv_tage', 30);
        $paket = App::post('standard_paket');
        if ($inaktivTage <= $aktivTage) {
            App::melden('„Inaktiv ab" muss größer sein als „aktiv bis" – sonst gäbe es keine Stufe dazwischen.', 'fehler');
            App::weiter('/master/einstellungen.php');
        }
        if (!isset(Pakete::aktive()[$paket])) {
            App::melden('Als Standard lässt sich nur ein angebotenes Paket wählen.', 'fehler');
            App::weiter('/master/einstellungen.php');
        }
        Plattform::setzen('aktiv_tage', $aktivTage);
        Plattform::setzen('inaktiv_tage', $inaktivTage);
        Plattform::setzen('test_tage', App::postInt('test_tage', 14));
        Plattform::setzen('support_minuten', App::postInt('support_minuten', 60));
        Plattform::setzen('standard_paket', $paket);
        $nachher = Plattform::alle();
        $diffV = array_diff_assoc(array_map('strval', $vorher), array_map('strval', $nachher));
        if ($diffV !== []) {
            Betreiberlog::schreiben('SETTINGS_CHANGED', ['objekt' => 'einstellungen',
                'vorher' => $diffV, 'nachher' => array_intersect_key($nachher, $diffV)]);
        }
        App::melden('Einstellungen gespeichert.');
    }

    if ($aktion === 'betreiber_neu') {
        Betreiber::fordern('betreiber.write');
        [$id, $token, $fehler] = Betreiber::anlegen(App::post('email'), App::post('name'));
        if ($id === 0) {
            App::melden($fehler, 'fehler');
        } else {
            $link = App::absolut('/passwort.php?token=' . $token);
            $ok = Mail::senden(strtolower(App::post('email')), 'Dein Zugang zur TeePilot Betreiberzentrale',
                'Hallo ' . App::post('name') . ",\n\n" . Betreiber::name() . ' hat dich als Master Admin der TeePilot '
                . "Betreiberzentrale eingetragen. Über diesen Link vergibst du dein Passwort:\n\n" . $link
                . "\n\nDer Link gilt sieben Tage.", ['knopf_text' => 'Passwort vergeben', 'knopf_url' => $link, 'protokoll' => false]);
            App::melden($ok ? 'Betreiber angelegt, Einladung verschickt.'
                            : 'Betreiber angelegt, aber die Mail ging nicht raus. Neuer Link: php bin/betreiber.php link ' . strtolower(App::post('email')),
                        $ok ? 'erfolg' : 'warnung');
        }
    }

    if ($aktion === 'betreiber_aktiv') {
        Betreiber::fordern('betreiber.write');
        $bid = App::postInt('betreiber_id');
        $aktiv = App::postBool('aktiv') ? 1 : 0;
        $b = DB::one('SELECT id, aktiv FROM betreiber WHERE id = :id', ['id' => $bid]);
        if ($b === null) {
            App::melden('Diesen Betreiber gibt es nicht.', 'fehler');
        } elseif ($bid === Betreiber::id() && $aktiv === 0) {
            App::melden('Den eigenen Zugang kann man nicht sperren.', 'fehler');
        } else {
            DB::update('betreiber', ['aktiv' => $aktiv], 'id = :id', ['id' => $bid]);
            Betreiberlog::schreiben('OPERATOR_UPDATED', ['objekt' => 'betreiber', 'objekt_id' => $bid,
                'vorher' => ['aktiv' => (int) $b['aktiv']], 'nachher' => ['aktiv' => $aktiv]]);
            App::melden($aktiv === 1 ? 'Zugang wieder aktiv.' : 'Zugang gesperrt. Eine laufende Sitzung endet mit dem nächsten Klick.');
        }
    }
    App::weiter('/master/einstellungen.php');
}

$werte = Plattform::alle();
$betreiber = DB::all("SELECT id, email, name, rolle, aktiv, letzter_login, erstellt,
                             CASE WHEN reset_token != '' AND reset_bis > :j THEN 1 ELSE 0 END AS einladung_offen
                      FROM betreiber ORDER BY aktiv DESC, name", ['j' => Util::jetzt()]);

$titel = 'Einstellungen';
$unter = 'Schwellen, Vorgaben und wer Zugang zur Betreiberzentrale hat.';
$bereich = 'einstellungen';
$brotkrumen = [['Übersicht', '/master/'], ['Einstellungen', null]];
require __DIR__ . '/partials/kopf.php';
?>

<div class="raster raster--2">
  <?= karteAuf('Vorgaben') ?>
    <form class="karte__koerper" method="post" action="<?= Util::attr(App::url('/master/einstellungen.php')) ?>">
      <?= Auth::csrfFeld() ?><input type="hidden" name="aktion" value="speichern">
      <h3 class="klein versal gedimmt-2">Nutzung einer Instanz</h3>
      <div class="feld-reihe feld-reihe--2">
        <div class="feld"><label class="feld__label" for="e-aktiv">Aktiv genutzt bis (Tage)</label>
          <input class="eingabe" id="e-aktiv" name="aktiv_tage" type="number" min="1" max="90" value="<?= (int) $werte['aktiv_tage'] ?>"></div>
        <div class="feld"><label class="feld__label" for="e-inaktiv">Inaktiv ab (Tage)</label>
          <input class="eingabe" id="e-inaktiv" name="inaktiv_tage" type="number" min="2" max="365" value="<?= (int) $werte['inaktiv_tage'] ?>"></div>
      </div>
      <p class="klein gedimmt">Dazwischen gilt „wenig genutzt". Grundlage ist die letzte Anmeldung oder Änderung durch das eigene Team –
        Zugriffe des Supports zählen nicht.</p>
      <h3 class="klein versal gedimmt-2 mt-4">Neue Instanzen</h3>
      <div class="feld-reihe feld-reihe--2">
        <div class="feld"><label class="feld__label" for="e-test">Testphase (Tage)</label>
          <input class="eingabe" id="e-test" name="test_tage" type="number" min="1" max="90" value="<?= (int) $werte['test_tage'] ?>"></div>
        <div class="feld"><label class="feld__label" for="e-paket">Vorausgewähltes Paket</label>
          <select class="eingabe" id="e-paket" name="standard_paket">
            <?php foreach (Pakete::aktive() as $k => $p): ?>
              <option value="<?= Util::attr($k) ?>"<?= (string) $werte['standard_paket'] === $k ? ' selected' : '' ?>><?= Util::h((string) $p['name']) ?></option>
            <?php endforeach; ?>
          </select></div>
      </div>
      <h3 class="klein versal gedimmt-2 mt-4">Support Mode</h3>
      <div class="feld"><label class="feld__label" for="e-support">Sitzung endet nach (Minuten)</label>
        <input class="eingabe" id="e-support" name="support_minuten" type="number" min="5" max="240" value="<?= (int) $werte['support_minuten'] ?>"></div>
      <p class="klein gedimmt">Benachrichtigungen verschickt die Betreiberzentrale bisher nicht; was Aufmerksamkeit braucht, steht auf der Übersicht.</p>
      <button class="btn btn--primaer" type="submit">Speichern</button>
    </form>
  </div>

  <?= karteAuf('Betreiber') ?>
    <div class="tabelle-huelle"><table class="tabelle tabelle--eng">
      <thead><tr><th>Name</th><th>Rolle</th><th>Status</th><th>Zuletzt</th><th class="aktionen"><span class="nur-lesbar">Aktionen</span></th></tr></thead>
      <tbody>
        <?php foreach ($betreiber as $b): $aktiv = (int) $b['aktiv'] === 1; ?>
          <tr><td class="tabelle__haupt"><?= Util::h((string) $b['name']) ?><div class="klein gedimmt"><?= Util::h((string) $b['email']) ?></div></td>
            <td><?= Util::h(Betreiber::rollenName((string) $b['rolle'])) ?></td>
            <td><?= !$aktiv ? pille('Gesperrt', 'gefahr') : ((int) $b['einladung_offen'] === 1 && !$b['letzter_login'] ? pille('Einladung offen', 'info') : pille('Aktiv', 'erfolg')) ?></td>
            <td><?= wann($b['letzter_login'] ?: null) ?></td>
            <td class="aktionen"><?php if ((int) $b['id'] !== Betreiber::id()): ?>
              <form method="post" action="<?= Util::attr(App::url('/master/einstellungen.php')) ?>">
                <?= Auth::csrfFeld() ?><input type="hidden" name="aktion" value="betreiber_aktiv">
                <input type="hidden" name="betreiber_id" value="<?= (int) $b['id'] ?>"><input type="hidden" name="aktiv" value="<?= $aktiv ? '' : '1' ?>">
                <button class="btn btn--klein<?= $aktiv ? ' btn--gefahr' : '' ?>" type="submit"
                        data-bestaetigen="<?= $aktiv ? 'Zugang von ' . Util::attr((string) $b['name']) . ' zur Betreiberzentrale sperren?' : 'Zugang wieder freigeben?' ?>"><?= $aktiv ? 'Sperren' : 'Freigeben' ?></button>
              </form>
            <?php else: ?><span class="klein gedimmt">du</span><?php endif; ?></td></tr>
        <?php endforeach; ?>
      </tbody>
    </table></div>
    <form class="karte__koerper" method="post" action="<?= Util::attr(App::url('/master/einstellungen.php')) ?>">
      <?= Auth::csrfFeld() ?><input type="hidden" name="aktion" value="betreiber_neu">
      <h3 class="klein versal gedimmt-2">Weiteren Master Admin einladen</h3>
      <div class="feld-reihe feld-reihe--2">
        <div class="feld"><label class="feld__label" for="n-bname">Name</label><input class="eingabe" id="n-bname" name="name" required maxlength="160"></div>
        <div class="feld"><label class="feld__label" for="n-bemail">E-Mail</label><input class="eingabe" id="n-bemail" name="email" type="email" required maxlength="190"></div>
      </div>
      <p class="klein gedimmt">Die Person bekommt einen Link, über den sie ihr Passwort selbst vergibt. Master Admins sehen alle Instanzen
        und können den Support Mode öffnen – vergib den Zugang entsprechend sparsam.</p>
      <button class="btn" type="submit" data-bestaetigen="Diese Person bekommt vollen Zugriff auf alle Instanzen. Einladen?">Einladen</button>
    </form>
  </div>
</div>

<?php require __DIR__ . '/partials/fuss.php';
