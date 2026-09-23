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

    if ($aktion === 'rechnungsabsender') {
        Betreiber::fordern('rechnungen.write');
        $vorher = Plattform::alle();
        foreach (['firma', 'inhaber', 'strasse', 'plz', 'ort', 'email', 'telefon', 'web', 'ust_id', 'steuernummer', 'bank', 'text'] as $k) {
            Plattform::setzen('rg_' . $k, mb_substr(App::post($k), 0, $k === 'text' ? 1000 : 200));
        }
        Plattform::setzen('rg_land', strtoupper(substr(App::post('land', 'DE'), 0, 2)) ?: 'DE');
        Plattform::setzen('rg_praefix', substr(preg_replace('/[^A-Z0-9]/', '', strtoupper(App::post('praefix'))) ?: 'TP', 0, 8));
        Plattform::setzen('rg_zahlungsziel', App::postInt('zahlungsziel', 14));
        Plattform::setzen('rg_steuersatz', App::postInt('steuersatz', 19));
        Plattform::setzen('rg_kleinunternehmer', App::postBool('kleinunternehmer') ? 1 : 0);
        $nachher = Plattform::alle();
        $diffV = array_diff_assoc(array_map('strval', $vorher), array_map('strval', $nachher));
        if ($diffV !== []) {
            Betreiberlog::schreiben('SETTINGS_CHANGED', ['objekt' => 'rechnungsabsender',
                'vorher' => $diffV, 'nachher' => array_intersect_key($nachher, $diffV)]);
        }
        App::melden('Rechnungsabsender gespeichert. Er gilt für Rechnungen, die ab jetzt ausgestellt werden.');
        App::weiter('/master/einstellungen.php#rechnungsabsender');
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

<div class="karte mt-5" id="rechnungsabsender">
  <div class="karte__kopf"><h2>Rechnungsabsender</h2>
    <?php $fehlt = Betreiberrechnungen::absenderLuecken(); ?>
    <div class="karte__kopf-aktionen"><?= $fehlt === [] ? pille('vollständig', 'erfolg') : pille('fehlt: ' . implode(', ', $fehlt), 'warnung') ?></div></div>
  <form class="karte__koerper" method="post" action="<?= Util::attr(App::url('/master/einstellungen.php')) ?>">
    <?= Auth::csrfFeld() ?><input type="hidden" name="aktion" value="rechnungsabsender">
    <p class="klein gedimmt">Wer die Rechnungen an die Instanzen stellt. Firma, Anschrift und Steuernummer oder USt-IdNr. sind Pflicht (§ 14 UStG).
      Beim Ausstellen wird der Stand übernommen – spätere Änderungen verändern keine alte Rechnung.</p>
    <div class="raster raster--2">
      <div>
        <?php foreach (['firma' => 'Firma', 'inhaber' => 'Inhaber / Geschäftsführung', 'strasse' => 'Straße und Hausnummer'] as $k => $l): ?>
          <div class="feld"><label class="feld__label" for="rg-<?= $k ?>"><?= $l ?></label>
            <input class="eingabe" id="rg-<?= $k ?>" name="<?= $k ?>" value="<?= Util::attr((string) Plattform::einstellung('rg_' . $k)) ?>"></div>
        <?php endforeach; ?>
        <div class="feld-reihe feld-reihe--3">
          <div class="feld"><label class="feld__label" for="rg-plz">PLZ</label><input class="eingabe" id="rg-plz" name="plz" value="<?= Util::attr((string) Plattform::einstellung('rg_plz')) ?>"></div>
          <div class="feld"><label class="feld__label" for="rg-ort">Ort</label><input class="eingabe" id="rg-ort" name="ort" value="<?= Util::attr((string) Plattform::einstellung('rg_ort')) ?>"></div>
          <div class="feld"><label class="feld__label" for="rg-land">Land</label><input class="eingabe" id="rg-land" name="land" maxlength="2" value="<?= Util::attr((string) Plattform::einstellung('rg_land')) ?>"></div>
        </div>
        <div class="feld-reihe feld-reihe--3">
          <div class="feld"><label class="feld__label" for="rg-email">E-Mail</label><input class="eingabe" id="rg-email" type="email" name="email" value="<?= Util::attr((string) Plattform::einstellung('rg_email')) ?>"></div>
          <div class="feld"><label class="feld__label" for="rg-telefon">Telefon</label><input class="eingabe" id="rg-telefon" name="telefon" value="<?= Util::attr((string) Plattform::einstellung('rg_telefon')) ?>"></div>
          <div class="feld"><label class="feld__label" for="rg-web">Website</label><input class="eingabe" id="rg-web" name="web" value="<?= Util::attr((string) Plattform::einstellung('rg_web')) ?>"></div>
        </div>
      </div>
      <div>
        <div class="feld-reihe feld-reihe--2">
          <div class="feld"><label class="feld__label" for="rg-ust">USt-IdNr.</label><input class="eingabe" id="rg-ust" name="ust_id" value="<?= Util::attr((string) Plattform::einstellung('rg_ust_id')) ?>"></div>
          <div class="feld"><label class="feld__label" for="rg-stnr">Steuernummer</label><input class="eingabe" id="rg-stnr" name="steuernummer" value="<?= Util::attr((string) Plattform::einstellung('rg_steuernummer')) ?>"></div>
        </div>
        <div class="feld"><label class="feld__label" for="rg-bank">Bankverbindung</label>
          <input class="eingabe" id="rg-bank" name="bank" value="<?= Util::attr((string) Plattform::einstellung('rg_bank')) ?>" placeholder="Bank · IBAN DE… · BIC …"></div>
        <div class="feld-reihe feld-reihe--3">
          <div class="feld"><label class="feld__label" for="rg-praefix">Nummernkreis</label>
            <input class="eingabe mono" id="rg-praefix" name="praefix" maxlength="8" value="<?= Util::attr((string) Plattform::einstellung('rg_praefix')) ?>">
            <div class="feld__hinweis">Ergibt <?= Util::h((string) Plattform::einstellung('rg_praefix')) ?>-<?= date('Y') ?>-0001</div></div>
          <div class="feld"><label class="feld__label" for="rg-ziel">Zahlungsziel (Tage)</label>
            <input class="eingabe" id="rg-ziel" name="zahlungsziel" type="number" min="0" max="90" value="<?= Plattform::zahl('rg_zahlungsziel') ?>"></div>
          <div class="feld"><label class="feld__label" for="rg-satz">Steuersatz (%)</label>
            <input class="eingabe" id="rg-satz" name="steuersatz" type="number" min="0" max="30" value="<?= Plattform::zahl('rg_steuersatz') ?>"></div>
        </div>
        <label class="haken mb-3"><input type="checkbox" name="kleinunternehmer" value="1"<?= Plattform::zahl('rg_kleinunternehmer') === 1 ? ' checked' : '' ?>>
          <span class="haken__text">Kleinunternehmer nach § 19 UStG<span class="haken__hinweis">Dann ohne Umsatzsteuer und mit dem Hinweis auf § 19.</span></span></label>
        <div class="feld"><label class="feld__label" for="rg-text">Standard-Einleitung</label>
          <textarea class="eingabe" id="rg-text" name="text" rows="2" maxlength="1000" placeholder="z. B. Für die Nutzung von TeePilot berechnen wir:"><?= Util::h((string) Plattform::einstellung('rg_text')) ?></textarea></div>
      </div>
    </div>
    <p class="klein gedimmt">Die Paketpreise gelten auf Rechnungen als Nettopreise; die Umsatzsteuer kommt hinzu.</p>
    <button class="btn btn--primaer" type="submit">Speichern</button>
  </form>
</div>

<?php require __DIR__ . '/partials/fuss.php';
