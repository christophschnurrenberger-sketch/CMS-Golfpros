<?php
/**
 * Eine Instanz im Detail – mit allem, was ein Betreiber an ihr tun kann.
 *
 * Jede Handlung ist ein POST mit CSRF-Merkmal und eigenem Recht, und
 * jede landet im Audit-Log (das erledigen die Dienste in Instanzen,
 * Pakete, Abos und Support – diese Seite ruft sie nur auf). Die Instanz
 * kommt immer aus der Datenbank, nie aus einem Formularfeld: Die ID in
 * der Adresse sagt nur, welche geladen wird, nicht, was mit ihr geschieht.
 */
require __DIR__ . '/partials/start.php';

$id = App::getInt('id', App::postInt('id'));
$ws = Instanzen::finden($id);
if ($ws === null) {
    http_response_code(404);
    $titel = 'Instanz nicht gefunden';
    $bereich = 'instanzen';
    $brotkrumen = [['Übersicht', '/master/'], ['Instanzen', '/master/instanzen.php'], ['Nicht gefunden', null]];
    require __DIR__ . '/partials/kopf.php';
    echo leerzustand('building', 'Diese Instanz gibt es nicht', 'Vielleicht wurde sie gelöscht. Im Audit-Log steht, wann und von wem.',
        [['Zur Liste', '/master/instanzen.php', 'arrow-left'], ['Audit-Log', '/master/protokoll.php?aktion=TENANT_DELETED', 'shield']]);
    require __DIR__ . '/partials/fuss.php';
    exit;
}

$reiterAlias = ['activity' => 'aktivitaet', 'users' => 'benutzer', 'subscription' => 'paket', 'usage' => 'nutzung',
                'settings' => 'einstellungen', 'overview' => 'uebersicht'];
$reiter = App::get('reiter', 'uebersicht');
$reiter = $reiterAlias[$reiter] ?? $reiter;
$reiterListe = ['uebersicht' => 'Übersicht', 'benutzer' => 'Benutzer', 'paket' => 'Paket', 'nutzung' => 'Nutzung',
                'aktivitaet' => 'Aktivität', 'einstellungen' => 'Einstellungen', 'audit' => 'Audit'];
if (!isset($reiterListe[$reiter])) {
    $reiter = 'uebersicht';
}
$hier = '/master/instanz.php?id=' . $id;

/* --------------------------------------------------------- Handlungen --- */

if (App::istPost()) {
    Auth::csrfFordern();
    $aktion = App::aktion();
    $grund = App::post('grund');
    $zurueck = $hier . '&reiter=' . rawurlencode(App::post('reiter', $reiter));

    switch ($aktion) {
        case 'status':
            Betreiber::fordern('instanzen.status');
            [$ok, $text] = Instanzen::statusSetzen($id, App::post('neu'), App::post('erwartet'), $grund);
            App::melden($text, $ok ? 'erfolg' : 'fehler');
            break;

        case 'loeschen':
            Betreiber::fordern('instanzen.delete');
            [$ok, $text] = Instanzen::loeschen($id, App::postRoh('bestaetigung'), $grund);
            App::melden($text, $ok ? 'erfolg' : 'fehler');
            if ($ok) {
                App::weiter('/master/instanzen.php');
            }
            break;

        case 'bearbeiten':
            Betreiber::fordern('instanzen.write');
            $fehler = Instanzen::bearbeiten($id, $_POST);
            if ($fehler === []) {
                App::melden('Gespeichert.');
            } else {
                App::melden(implode(' ', $fehler), 'fehler');
            }
            break;

        case 'paket':
            Betreiber::fordern('pakete.assign');
            $abo = App::postBool('vertrag') ? [
                'laufzeit' => App::post('laufzeit'), 'beginn' => App::post('beginn'), 'test_bis' => App::post('test_bis'),
                'preis_cent' => Util::centAus(App::post('preis')), 'notiz' => App::post('notiz'),
            ] : null;
            [$ok, $text] = Instanzen::paketAendern($id, App::post('paket'), $abo, $grund);
            App::melden($text, $ok ? 'erfolg' : 'fehler');
            break;

        case 'kuendigen':
            Betreiber::fordern('pakete.assign');
            $ok = Abos::kuendigen($id, App::post('zum'), $grund);
            App::melden($ok ? 'Kündigung vorgemerkt. Die Instanz läuft bis zum Vertragsende weiter.'
                            : 'Kündigung nicht möglich – es gibt keine laufenden Vertragsdaten oder das Datum fehlt.',
                        $ok ? 'erfolg' : 'fehler');
            break;

        case 'einladen':
            Betreiber::fordern('benutzer.write');
            $ok = Instanzen::einladen($id, App::postInt('user_id'));
            App::melden($ok ? 'Neuer Link zum Passwortsetzen verschickt (gültig sieben Tage).'
                            : 'Die Einladung konnte nicht verschickt werden. Prüfe den Mailversand unter System.',
                        $ok ? 'erfolg' : 'fehler');
            break;

        case 'benutzer_aktiv':
            Betreiber::fordern('benutzer.write');
            [$ok, $text] = Instanzen::benutzerAktiv($id, App::postInt('user_id'), App::postBool('aktiv'));
            App::melden($text, $ok ? 'erfolg' : 'fehler');
            break;

        case 'notiz':
            Betreiber::fordern('instanzen.write');
            App::melden(Instanzen::notizHinzufuegen($id, App::post('text')) ? 'Notiz gespeichert.' : 'Die Notiz ist leer.',
                App::post('text') !== '' ? 'erfolg' : 'fehler');
            break;
    }
    App::weiter($zurueck);
}

/* ------------------------------------------------------------- Daten --- */

$abo = Abos::aktuell($id);
$nutzung = in_array($reiter, ['uebersicht', 'nutzung'], true) ? Instanzen::nutzung($id) : [];
$status = (string) $ws['status'];
$paket = Pakete::finden((string) $ws['plan']);
$adresse = (string) $ws['domain'] !== '' ? 'https://' . $ws['domain'] : App::absolut('/?w=' . rawurlencode((string) $ws['slug']));

$titel = (string) $ws['name'];
$bereich = 'instanzen';
$brotkrumen = [['Übersicht', '/master/'], ['Instanzen', '/master/instanzen.php'], [(string) $ws['name'], null]];
$unter = Instanzen::statusPille($status) . ' ' . paketPille((string) $ws['plan']) . demoPille($ws)
       . ' <span class="gedimmt">· ' . Util::h((string) ($ws['inhaber'] ?? 'kein Inhaber'))
       . ((string) ($ws['club'] ?? '') !== '' ? ' · ' . Util::h((string) $ws['club']) : '') . '</span>';

ob_start(); ?>
  <button class="btn" data-modal-auf="modal-support"<?= $status === 'archiviert' ? ' disabled title="Archivierte Instanzen erst reaktivieren."' : '' ?>>
    <?= Icon::svg('eye', 15) ?> Als Support öffnen</button>
  <div class="aufklapp">
    <button class="btn" data-aufklapp aria-haspopup="true"><?= Icon::svg('more', 15) ?> Aktionen</button>
    <div class="aufklapp__menue">
      <a class="aufklapp__eintrag" href="<?= Util::attr(App::url($hier . '&reiter=einstellungen')) ?>"><?= Icon::svg('edit', 16) ?> Bearbeiten</a>
      <a class="aufklapp__eintrag" href="<?= Util::attr(App::url($hier . '&reiter=paket')) ?>"><?= Icon::svg('layers', 16) ?> Paket ändern</a>
      <div class="aufklapp__trenner"></div>
      <?php foreach (Instanzen::ZIELE as $ziel): if ($ziel === $status) { continue; } ?>
        <button class="aufklapp__eintrag<?= in_array($ziel, ['gesperrt', 'archiviert'], true) ? ' aufklapp__eintrag--gefahr' : '' ?>"
                data-modal-auf="modal-status-<?= $ziel ?>">
          <?= Icon::svg(['aktiv' => 'play', 'pausiert' => 'pause', 'gesperrt' => 'lock', 'archiviert' => 'folder'][$ziel], 16) ?>
          <?= Util::h(['aktiv' => $status === 'archiviert' ? 'Reaktivieren' : 'Aktivieren', 'pausiert' => 'Pausieren',
                       'gesperrt' => 'Sperren', 'archiviert' => 'Archivieren'][$ziel]) ?></button>
      <?php endforeach; ?>
      <?php if ($status === 'archiviert'): ?>
        <div class="aufklapp__trenner"></div>
        <button class="aufklapp__eintrag aufklapp__eintrag--gefahr" data-modal-auf="modal-loeschen"><?= Icon::svg('trash', 16) ?> Endgültig löschen</button>
      <?php endif; ?>
    </div>
  </div>
<?php $aktionen = (string) ob_get_clean();
$skripte = '<script src="' . Util::attr(App::asset('assets/js/master.js')) . '"></script>';
require __DIR__ . '/partials/kopf.php';
?>

<div class="instanz-kopf mb-4">
  <span>ID <span class="mono">#<?= $id ?></span>
    <button class="rundknopf rundknopf--klein" data-kopieren="<?= $id ?>" aria-label="ID kopieren" title="ID kopieren"><?= Icon::svg('copy', 14) ?></button></span>
  <span>Angelegt <?= Util::h(Util::datum((string) $ws['erstellt'])) ?></span>
  <span>Letzte Aktivität <?= wann($ws['letzte_aktivitaet'] ?: null) ?></span>
  <span><?= Instanzen::gesundheitPille($ws['letzte_aktivitaet'] ?: null) ?></span>
</div>

<?php if (!Instanzen::zugangErlaubt($status)): ?>
  <div class="hinweis hinweis--<?= $status === 'pausiert' ? 'warnung' : 'gefahr' ?> mb-4"><?= Icon::svg('alert', 17) ?>
    <div class="hinweis__text"><b><?= Util::h(Instanzen::statusName($status)) ?>:</b> <?= Util::h(Instanzen::WIRKUNG[$status]) ?></div></div>
<?php endif; ?>

<nav class="reiter mb-4" aria-label="Bereiche der Instanz">
  <?php foreach ($reiterListe as $k => $name): ?>
    <a class="reiter__teil<?= $reiter === $k ? ' ist-aktiv' : '' ?>" href="<?= Util::attr(App::url($hier . '&reiter=' . $k)) ?>"
       <?= $reiter === $k ? 'aria-current="page"' : '' ?>><?= Util::h($name) ?></a>
  <?php endforeach; ?>
</nav>

<?php if ($reiter === 'uebersicht'):
  $zeitleiste = Betreiberlog::liste(['instanz_id' => $id], 12);
  $notizen = Instanzen::notizen($id); ?>
  <div class="raster raster--haupt-neben">
    <div class="stapel">
      <?= karteAuf('Stammdaten') ?>
        <div class="karte__koerper">
          <dl class="angaben">
            <?= angabe('Golfpro', Util::h((string) ($ws['inhaber'] ?? '—')) . ((string) ($ws['inhaber_email'] ?? '') !== ''
                ? ' · <a href="mailto:' . Util::attr((string) $ws['inhaber_email']) . '">' . Util::h((string) $ws['inhaber_email']) . '</a>' : '')) ?>
            <?= angabe('Club / Anlage', Util::h((string) ($ws['club'] ?? '')) ?: '<span class="gedimmt">—</span>') ?>
            <?= angabe('Art', Util::h(Instanzen::TYPEN[$ws['typ']] ?? (string) $ws['typ'])) ?>
            <?= angabe('Website', '<a href="' . Util::attr($adresse) . '" target="_blank" rel="noopener noreferrer">' . Util::h($adresse) . '</a>'
                . ((int) $ws['aktiv'] !== 1 ? ' <span class="pille pille--gefahr">offline</span>' : '')) ?>
            <?= angabe('Kurzadresse', '<span class="mono">' . Util::h((string) $ws['slug']) . '</span>') ?>
            <?= angabe('Eigene Domain', (string) $ws['domain'] !== '' ? '<span class="mono">' . Util::h((string) $ws['domain']) . '</span>' : '<span class="gedimmt">keine</span>') ?>
            <?= angabe('Währung · Zeitzone', Util::h((string) $ws['waehrung'] . ' · ' . (string) $ws['zeitzone'])) ?>
            <?= angabe('Einrichtung', (int) $ws['onboarding_schritt'] >= 9 ? 'abgeschlossen' : 'Schritt ' . (int) $ws['onboarding_schritt'] . ' von 9') ?>
          </dl>
        </div>
      </div>

      <?= karteAuf('Paket und Vertrag', '<a class="btn btn--klein" href="' . Util::attr(App::url($hier . '&reiter=paket')) . '">Ändern</a>') ?>
        <div class="karte__koerper">
          <dl class="angaben">
            <?= angabe('Paket', paketPille((string) $ws['plan'])) ?>
            <?= angabe('Vertrag', Util::h(aboText($abo))) ?>
            <?= angabe('Benutzer', (int) $nutzung['benutzer_aktiv'] . ' aktiv'
                . ($paket !== null && (int) $paket['team_grenze'] > 0 ? ' von ' . (int) $paket['team_grenze'] . ' im Paket' : ' · Team im Paket unbegrenzt')) ?>
          </dl>
        </div>
      </div>

      <div class="raster raster--3">
        <?= kennzahl('Kunden', Util::zahl($nutzung['kunden']), ['fuss' => '+' . $nutzung['kunden_neu_30'] . ' in 30 Tagen']) ?>
        <?= kennzahl('Termine diesen Monat', Util::zahl($nutzung['termine_monat']), ['fuss' => Util::zahl($nutzung['termine']) . ' insgesamt']) ?>
        <?= kennzahl('Seiten online', Util::zahl($nutzung['seiten']), ['fuss' => $nutzung['beitraege'] . ' Beiträge']) ?>
      </div>

      <?= karteAuf('Verlauf', '<a class="klein" href="' . Util::attr(App::url($hier . '&reiter=audit')) . '">Audit</a>') ?>
        <div class="karte__koerper">
          <?php if ($zeitleiste === []): ?>
            <p class="gedimmt mb-0">Noch keine Einträge. Instanzen aus der Zeit vor der Betreiberzentrale haben hier keinen Verlauf.</p>
          <?php else: ?>
            <div class="strahl">
              <?php foreach ($zeitleiste as $z):
                $farbe = ['CRITICAL' => 'gefahr', 'WARNING' => 'marke'][$z['schwere']] ?? ''; ?>
                <div class="strahl__punkt<?= $farbe !== '' ? ' strahl__punkt--' . $farbe : '' ?>">
                  <div class="strahl__zeit"><?= Util::h(Util::datumZeit((string) $z['zeit'])) ?> · <?= Util::h((string) $z['akteur'] ?: 'System') ?></div>
                  <a class="strahl__titel" href="<?= Util::attr(App::url('/master/protokoll.php?id=' . (int) $z['id'])) ?>"><?= Util::h(Betreiberlog::text((string) $z['aktion'])) ?></a>
                  <?php if ((string) $z['grund'] !== ''): ?><div class="strahl__text">Grund: <?= Util::h((string) $z['grund']) ?></div><?php endif; ?>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <?= karteAuf('Interne Notizen', '<span class="pille pille--offen" title="Diese Notizen erscheinen nie in der Instanz.">nur Betreiber</span>') ?>
      <div class="karte__koerper">
        <form method="post" action="<?= Util::attr(App::url('/master/instanz.php')) ?>" class="mb-4">
          <?= Auth::csrfFeld() ?>
          <input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="aktion" value="notiz">
          <label class="nur-lesbar" for="notiz-text">Neue Notiz</label>
          <textarea class="eingabe" id="notiz-text" name="text" rows="3" maxlength="4000" required
                    placeholder="Was das Team wissen sollte – Absprachen, Besonderheiten, offene Fragen."></textarea>
          <button class="btn btn--klein mt-2" type="submit">Notiz speichern</button>
        </form>
        <?php if ($notizen === []): ?>
          <p class="klein gedimmt mb-0">Noch keine Notizen. Sichtbar nur in der Betreiberzentrale – nie in der Instanz.</p>
        <?php else: ?>
          <ul class="notizen">
            <?php foreach ($notizen as $n): ?>
              <li><div class="notizen__text"><?= nl2br(Util::h((string) $n['text'])) ?></div>
                <div class="winzig gedimmt-2"><?= Util::h((string) $n['akteur']) ?> · <?= Util::h(Util::datumZeit((string) $n['erstellt'])) ?></div></li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </div>
    </div>
  </div>

<?php elseif ($reiter === 'benutzer'):
  $benutzer = Instanzen::benutzer($id); ?>
  <?= karteAuf('Benutzer dieser Instanz', '<span class="klein gedimmt">' . count($benutzer) . ' Zugänge · Passwörter sind nirgends einsehbar</span>') ?>
    <?php if ($benutzer === []): ?>
      <div class="karte__koerper"><p class="gedimmt mb-0">Diese Instanz hat keine Benutzer.</p></div>
    <?php else: ?>
      <div class="tabelle-huelle">
        <table class="tabelle">
          <thead><tr><th>Name</th><th>E-Mail</th><th>Rolle</th><th>Status</th><th>Letzte Anmeldung</th><th class="aktionen"><span class="nur-lesbar">Aktionen</span></th></tr></thead>
          <tbody>
            <?php foreach ($benutzer as $u): $aktiv = (int) $u['aktiv'] === 1; ?>
              <tr>
                <td class="tabelle__haupt"><?= Util::h((string) $u['name']) ?></td>
                <td><?= Util::h((string) $u['email']) ?></td>
                <td><?= Util::h(Auth::ROLLEN[$u['rolle']] ?? (string) $u['rolle']) ?></td>
                <td><?= !$aktiv ? pille('Deaktiviert', 'offen') : ((int) $u['einladung_offen'] === 1 && !$u['letzter_login']
                    ? pille('Einladung offen', 'info') : pille('Aktiv', 'erfolg')) ?></td>
                <td><?= wann($u['letzter_login'] ?: null) ?></td>
                <td class="aktionen">
                  <?php if ($aktiv): ?>
                  <form method="post" action="<?= Util::attr(App::url('/master/instanz.php')) ?>" style="display:inline">
                    <?= Auth::csrfFeld() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="reiter" value="benutzer">
                    <input type="hidden" name="aktion" value="einladen"><input type="hidden" name="user_id" value="<?= (int) $u['id'] ?>">
                    <button class="btn btn--klein" type="submit" data-bestaetigen="Einen neuen Link zum Passwortsetzen an <?= Util::attr((string) $u['email']) ?> schicken? Ein älterer Link wird damit ungültig.">
                      <?= Icon::svg('send', 14) ?> Link senden</button>
                  </form>
                  <?php endif; ?>
                  <form method="post" action="<?= Util::attr(App::url('/master/instanz.php')) ?>" style="display:inline">
                    <?= Auth::csrfFeld() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="reiter" value="benutzer">
                    <input type="hidden" name="aktion" value="benutzer_aktiv"><input type="hidden" name="user_id" value="<?= (int) $u['id'] ?>">
                    <input type="hidden" name="aktiv" value="<?= $aktiv ? '' : '1' ?>">
                    <button class="btn btn--klein<?= $aktiv ? ' btn--gefahr' : '' ?>" type="submit"
                            data-bestaetigen="<?= $aktiv ? 'Zugang von ' . Util::attr((string) $u['name']) . ' deaktivieren? Die Person kann sich danach nicht mehr anmelden.' : 'Zugang wieder aktivieren?' ?>">
                      <?= $aktiv ? 'Deaktivieren' : 'Aktivieren' ?></button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

<?php elseif ($reiter === 'paket'):
  $verlauf = Abos::verlauf($id);
  $paketLog = Betreiberlog::liste(['instanz_id' => $id, 'aktionen' => ['PLAN_CHANGED', 'SUBSCRIPTION_CHANGED']], 30);
  $moduleAn = Instanzen::moduleAn($id, (string) $ws['plan']); ?>
  <div class="raster raster--haupt-neben">
    <div class="stapel">
      <?= karteAuf('Aktuelles Paket: ' . Pakete::name((string) $ws['plan'])) ?>
        <div class="karte__koerper">
          <?php if ($paket === null): ?>
            <p class="gedimmt">Diese Instanz trägt ein Paket, das es nicht gibt. Sie sieht nur die Kernbereiche, bis ein gültiges Paket zugewiesen ist.</p>
          <?php else: ?>
            <p class="gedimmt"><?= Util::h((string) $paket['beschreibung']) ?></p>
            <dl class="angaben">
              <?= angabe('Listenpreis', (int) $paket['preis_monat_cent'] > 0 ? Util::h(Util::geld((int) $paket['preis_monat_cent'])) . ' / Monat' : 'keiner') ?>
              <?= angabe('Team', (int) $paket['team_grenze'] === 0 ? 'unbegrenzt' : 'bis ' . (int) $paket['team_grenze'] . ' Personen') ?>
              <?= angabe('Vertrag', Util::h(aboText($abo))) ?>
            </dl>
            <h3 class="klein versal gedimmt-2 mt-4">Module</h3>
            <ul class="modulliste">
              <?php foreach (Module::alle() as $key => $info):
                $imPaket = Pakete::erlaubt($key, (string) $ws['plan']);
                $an = in_array($key, $moduleAn, true); ?>
                <li class="<?= $imPaket ? '' : 'ist-aus' ?>">
                  <?= Icon::svg($imPaket ? 'check' : 'x', 14) ?> <?= Util::h((string) $info['name']) ?>
                  <?php if ($imPaket && !$an): ?><span class="winzig gedimmt-2">abgeschaltet</span><?php endif; ?>
                  <?php if (Module::istKern($key)): ?><span class="winzig gedimmt-2">Kern</span><?php endif; ?>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>
        </div>
      </div>

      <?= karteAuf('Paket oder Vertrag ändern') ?>
        <form class="karte__koerper" method="post" action="<?= Util::attr(App::url('/master/instanz.php')) ?>">
          <?= Auth::csrfFeld() ?>
          <input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="reiter" value="paket">
          <input type="hidden" name="aktion" value="paket">
          <div class="feld"><label class="feld__label" for="p-paket">Paket</label>
            <select class="eingabe" id="p-paket" name="paket">
              <?php foreach (Pakete::alle() as $k => $p): if ((int) $p['aktiv'] !== 1 && $k !== (string) $ws['plan']) { continue; } ?>
                <option value="<?= Util::attr($k) ?>"<?= $k === (string) $ws['plan'] ? ' selected' : '' ?>>
                  <?= Util::h((string) $p['name']) ?><?= (int) $p['preis_monat_cent'] > 0 ? ' · ' . Util::h(Util::geld((int) $p['preis_monat_cent'])) . ' / Monat' : '' ?><?= (int) $p['aktiv'] !== 1 ? ' (nicht mehr angeboten)' : '' ?></option>
              <?php endforeach; ?>
            </select>
            <div class="feld__hinweis">Beim Wechsel nach unten werden Module abgeschaltet, die das neue Paket nicht enthält. Die Daten bleiben erhalten.</div></div>
          <label class="haken mb-3"><input type="checkbox" name="vertrag" value="1"<?= $abo === null ? '' : '' ?>>
            <span class="haken__text">Vertragsdaten neu erfassen
              <span class="haken__hinweis">Schließt den laufenden Vertrag und beginnt einen neuen. Ohne Haken bleibt der Vertrag, wie er ist.</span></span></label>
          <div class="feld-reihe feld-reihe--2">
            <div class="feld"><label class="feld__label" for="p-laufzeit">Laufzeit</label>
              <select class="eingabe" id="p-laufzeit" name="laufzeit">
                <?php foreach (Abos::LAUFZEIT as $k => $v): ?>
                  <option value="<?= $k ?>"<?= ($abo['laufzeit'] ?? 'monat') === $k ? ' selected' : '' ?>><?= Util::h($v) ?></option>
                <?php endforeach; ?>
              </select></div>
            <div class="feld"><label class="feld__label" for="p-beginn">Beginn</label>
              <input class="eingabe" id="p-beginn" type="date" name="beginn" value="<?= date('Y-m-d') ?>"></div>
            <div class="feld"><label class="feld__label" for="p-test">Testphase bis (nur Testphase)</label>
              <input class="eingabe" id="p-test" type="date" name="test_bis" value="<?= date('Y-m-d', strtotime('+' . Plattform::zahl('test_tage') . ' days')) ?>"></div>
            <div class="feld"><label class="feld__label" for="p-preis">Betrag je Monat (nur Individuell)</label>
              <input class="eingabe" id="p-preis" name="preis" inputmode="decimal" placeholder="0,00"></div>
          </div>
          <div class="feld"><label class="feld__label" for="p-notiz">Notiz zum Vertrag</label>
            <input class="eingabe" id="p-notiz" name="notiz" maxlength="500"></div>
          <div class="feld"><label class="feld__label" for="p-grund">Grund (für das Audit-Log)</label>
            <input class="eingabe" id="p-grund" name="grund" maxlength="500" placeholder="z. B. Upgrade auf Kundenwunsch"></div>
          <button class="btn btn--primaer" type="submit" data-bestaetigen="Paket und – falls angehakt – Vertragsdaten jetzt ändern? Die Instanz sieht die Änderung sofort.">Speichern</button>
        </form>
      </div>
    </div>

    <div class="stapel">
      <?php if ($abo !== null && in_array((string) $abo['status'], ['aktiv', 'test'], true)): ?>
        <?= karteAuf('Kündigung vormerken') ?>
          <form class="karte__koerper" method="post" action="<?= Util::attr(App::url('/master/instanz.php')) ?>">
            <?= Auth::csrfFeld() ?>
            <input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="reiter" value="paket">
            <input type="hidden" name="aktion" value="kuendigen">
            <div class="feld"><label class="feld__label" for="k-zum">Wirksam zum</label>
              <input class="eingabe" id="k-zum" type="date" name="zum" required value="<?= date('Y-m-t') ?>"></div>
            <div class="feld"><label class="feld__label" for="k-grund">Grund</label>
              <input class="eingabe" id="k-grund" name="grund" maxlength="500"></div>
            <p class="klein gedimmt">Es gibt keine Abrechnung, die das Ende durchsetzt: Die Instanz läuft weiter, bis du sie pausierst oder archivierst.
              Anstehende Kündigungen zeigt die Übersicht.</p>
            <button class="btn" type="submit" data-bestaetigen="Kündigung vormerken?">Kündigung vormerken</button>
          </form>
        </div>
      <?php endif; ?>

      <?= karteAuf('Vertragsverlauf') ?>
        <?php if ($verlauf === []): ?>
          <div class="karte__koerper"><p class="gedimmt mb-0">Keine Vertragsdaten erfasst. Instanzen aus der Zeit vor der Betreiberzentrale
            haben keine – erfunden wird hier nichts.</p></div>
        <?php else: ?>
          <div class="tabelle-huelle"><table class="tabelle tabelle--eng">
            <thead><tr><th>Paket</th><th>Laufzeit</th><th>Status</th><th>Zeitraum</th></tr></thead>
            <tbody>
              <?php foreach ($verlauf as $v): ?>
                <tr><td><?= Util::h(Pakete::name((string) $v['paket'])) ?></td>
                  <td><?= Util::h(Abos::LAUFZEIT[$v['laufzeit']] ?? (string) $v['laufzeit']) ?><?= (int) $v['preis_cent'] > 0 ? '<div class="klein gedimmt">' . Util::h(Util::geld((int) $v['preis_cent'])) . '</div>' : '' ?></td>
                  <td><?= Util::h(Abos::STATUS[$v['status']] ?? (string) $v['status']) ?></td>
                  <td class="klein"><?= Util::h(Util::datum((string) $v['beginn'])) ?> – <?= $v['ende'] ? Util::h(Util::datum((string) $v['ende'])) : 'offen' ?>
                    <div class="gedimmt"><?= Util::h((string) $v['erstellt_von']) ?></div></td></tr>
              <?php endforeach; ?>
            </tbody>
          </table></div>
        <?php endif; ?>
      </div>

      <?= karteAuf('Paketwechsel') ?>
        <?php if ($paketLog === []): ?>
          <div class="karte__koerper"><p class="gedimmt mb-0">Noch keine Wechsel protokolliert.</p></div>
        <?php else: ?>
          <div class="karte__koerper">
            <div class="strahl">
              <?php foreach ($paketLog as $z): $v = Util::ausJson((string) $z['vorher'], []); $n = Util::ausJson((string) $z['nachher'], []); ?>
                <div class="strahl__punkt">
                  <div class="strahl__zeit"><?= Util::h(Util::datumZeit((string) $z['zeit'])) ?> · <?= Util::h((string) $z['akteur']) ?></div>
                  <div class="strahl__titel"><?= Util::h(Betreiberlog::text((string) $z['aktion'])) ?></div>
                  <div class="strahl__text"><?= (string) $z['aktion'] === 'PLAN_CHANGED'
                      ? Util::h((string) ($v['name'] ?? '?') . ' → ' . (string) ($n['name'] ?? '?'))
                      : Util::h((string) (Abos::LAUFZEIT[$n['laufzeit'] ?? ''] ?? ($n['status'] ?? ''))) ?>
                    <?= (string) $z['grund'] !== '' ? '<br>Grund: ' . Util::h((string) $z['grund']) : '' ?></div>
                </div>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

<?php elseif ($reiter === 'nutzung'):
  $moduleAn = Instanzen::moduleAn($id, (string) $ws['plan']); ?>
  <p class="klein gedimmt mb-4">Anzahlen aus der Datenbank der Instanz – keine Namen, keine Beträge, keine Inhalte.
    Wer die Inhalte sehen muss, öffnet die Instanz im Support Mode; das wird protokolliert.</p>
  <div class="raster raster--4 mb-5">
    <?= kennzahl('Aktive Benutzer', Util::zahl($nutzung['benutzer_aktiv']), ['fuss' => $nutzung['benutzer'] . ' Zugänge insgesamt']) ?>
    <?= kennzahl('Kunden', Util::zahl($nutzung['kunden']), ['fuss' => '+' . $nutzung['kunden_neu_30'] . ' in 30 Tagen']) ?>
    <?= kennzahl('Termine diesen Monat', Util::zahl($nutzung['termine_monat']), ['fuss' => $nutzung['termine_neu_30'] . ' in 30 Tagen gebucht']) ?>
    <?= kennzahl('Termine gesamt', Util::zahl($nutzung['termine'])) ?>
    <?= kennzahl('Seiten online', Util::zahl($nutzung['seiten']), ['fuss' => $nutzung['beitraege'] . ' Beiträge veröffentlicht']) ?>
    <?= kennzahl('Rechnungen', Util::zahl($nutzung['rechnungen'])) ?>
    <?= kennzahl('Newsletter versendet', Util::zahl($nutzung['newsletter'])) ?>
    <?= kennzahl('Speicher', Util::bytes((int) $nutzung['speicher']), ['fuss' => 'Uploads und private Dateien']) ?>
  </div>
  <div class="raster raster--2">
    <?= karteAuf('Eingeschaltete Module') ?>
      <div class="karte__koerper">
        <p class="klein gedimmt"><?= count($moduleAn) ?> von <?= count(Module::alle()) ?> Bereichen sind an.</p>
        <ul class="modulliste">
          <?php foreach ($moduleAn as $key): ?><li><?= Icon::svg('check', 14) ?> <?= Util::h(Module::name($key)) ?></li><?php endforeach; ?>
        </ul>
      </div>
    </div>
    <?= karteAuf('Anmeldung') ?>
      <div class="karte__koerper">
        <dl class="angaben">
          <?= angabe('Letzte Anmeldung', wann($nutzung['letzter_login'] ?: null)) ?>
          <?= angabe('Letzte Aktivität', wann($ws['letzte_aktivitaet'] ?: null)) ?>
          <?= angabe('Einordnung', Instanzen::gesundheitPille($ws['letzte_aktivitaet'] ?: null)) ?>
          <?= angabe('Kurse · Events', $nutzung['kurse'] . ' · ' . $nutzung['events']) ?>
        </dl>
      </div>
    </div>
  </div>

<?php elseif ($reiter === 'aktivitaet'):
  $eintraege = Instanzen::aktivitaet($id, 60);
  $objekte = ['customer' => 'Kunde', 'booking' => 'Termin', 'invoice' => 'Rechnung', 'order' => 'Bestellung', 'user' => 'Team',
              'page' => 'Seite', 'post' => 'Beitrag', 'service' => 'Leistung', 'workspace' => 'Instanz', 'campaign' => 'Kampagne',
              'location' => 'Standort', 'system' => 'System']; ?>
  <?= karteAuf('Was in der Instanz passiert ist', '<span class="klein gedimmt">Art und Zeitpunkt – ohne Inhalte</span>') ?>
    <?php if ($eintraege === []): ?>
      <div class="karte__koerper"><p class="gedimmt mb-0">Noch keine Aktivität protokolliert.</p></div>
    <?php else: ?>
      <div class="tabelle-huelle"><table class="tabelle tabelle--eng">
        <thead><tr><th>Wann</th><th>Wer</th><th>Was</th><th>Bereich</th></tr></thead>
        <tbody>
          <?php foreach ($eintraege as $a): ?>
            <tr><td class="umbruch-nein"><?= wann((string) $a['erstellt']) ?></td>
              <td><?= (int) $a['betreiber_id'] > 0 ? '<span class="pille pille--warnung">TeePilot Support</span>'
                  : ((int) $a['user_id'] > 0 ? Util::h((string) ($a['person'] ?? 'Benutzer #' . (int) $a['user_id'])) : '<span class="gedimmt">System</span>') ?></td>
              <td><?= Util::h(Audit::text((string) $a['aktion'])) ?></td>
              <td><?= Util::h($objekte[$a['objekt']] ?? (string) $a['objekt']) ?></td></tr>
          <?php endforeach; ?>
        </tbody>
      </table></div>
    <?php endif; ?>
  </div>

<?php elseif ($reiter === 'einstellungen'): ?>
  <div class="raster raster--haupt-neben">
    <?= karteAuf('Instanz bearbeiten') ?>
      <form class="karte__koerper" method="post" action="<?= Util::attr(App::url('/master/instanz.php')) ?>">
        <?= Auth::csrfFeld() ?>
        <input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="reiter" value="einstellungen">
        <input type="hidden" name="aktion" value="bearbeiten">
        <div class="feld-reihe feld-reihe--2">
          <div class="feld"><label class="feld__label" for="b-name">Name</label>
            <input class="eingabe" id="b-name" name="name" required maxlength="160" value="<?= Util::attr((string) $ws['name']) ?>"></div>
          <div class="feld"><label class="feld__label" for="b-typ">Art</label>
            <select class="eingabe" id="b-typ" name="typ">
              <?php foreach (Instanzen::TYPEN as $k => $v): ?><option value="<?= $k ?>"<?= $ws['typ'] === $k ? ' selected' : '' ?>><?= Util::h($v) ?></option><?php endforeach; ?>
            </select></div>
          <div class="feld"><label class="feld__label" for="b-slug">Kurzadresse</label>
            <input class="eingabe mono" id="b-slug" name="slug" required maxlength="64" value="<?= Util::attr((string) $ws['slug']) ?>">
            <div class="feld__hinweis">Ändern bricht Links, die mit ?w=<?= Util::h((string) $ws['slug']) ?> geteilt wurden.</div></div>
          <div class="feld"><label class="feld__label" for="b-domain">Eigene Domain</label>
            <input class="eingabe mono" id="b-domain" name="domain" maxlength="160" value="<?= Util::attr((string) $ws['domain']) ?>"></div>
          <div class="feld"><label class="feld__label" for="b-waehrung">Währung</label>
            <select class="eingabe" id="b-waehrung" name="waehrung">
              <?php foreach (Instanzen::WAEHRUNGEN as $k => $v): ?><option value="<?= $k ?>"<?= $ws['waehrung'] === $k ? ' selected' : '' ?>><?= Util::h($k . ' · ' . $v) ?></option><?php endforeach; ?>
            </select></div>
          <div class="feld"><label class="feld__label" for="b-zeitzone">Zeitzone</label>
            <input class="eingabe" id="b-zeitzone" name="zeitzone" value="<?= Util::attr((string) $ws['zeitzone']) ?>" list="zeitzonen">
            <datalist id="zeitzonen"><?php foreach (['Europe/Berlin', 'Europe/Vienna', 'Europe/Zurich', 'Europe/London', 'Europe/Paris', 'Europe/Madrid', 'Europe/Rome', 'Europe/Amsterdam'] as $z): ?><option value="<?= $z ?>"><?php endforeach; ?></datalist></div>
        </div>
        <button class="btn btn--primaer" type="submit">Speichern</button>
      </form>
    </div>
    <?= karteAuf('Status') ?>
      <div class="karte__koerper">
        <p><?= Instanzen::statusPille($status) ?></p>
        <p class="klein"><?= Util::h(Instanzen::WIRKUNG[$status] ?? '') ?></p>
        <p class="klein gedimmt mb-0">Status ändern über „Aktionen" oben rechts. Jede Änderung fragt nach und wird mit Grund protokolliert.</p>
      </div>
    </div>
  </div>

<?php elseif ($reiter === 'audit'):
  $seite = max(1, App::getInt('seite', 1));
  $gesamt = Betreiberlog::anzahl(['instanz_id' => $id]);
  $eintraege = Betreiberlog::liste(['instanz_id' => $id], 50, ($seite - 1) * 50); ?>
  <?= karteAuf('Audit-Log dieser Instanz', '<a class="klein" href="' . Util::attr(App::url('/master/protokoll.php?instanz=' . $id)) . '">Im Audit-Log filtern</a>') ?>
    <?php if ($eintraege === []): ?>
      <div class="karte__koerper"><p class="gedimmt mb-0">Keine Einträge.</p></div>
    <?php else: ?>
      <div class="tabelle-huelle"><table class="tabelle tabelle--eng">
        <thead><tr><th>Wann</th><th>Wer</th><th>Aktion</th><th>Schwere</th><th>Ergebnis</th><th>Grund</th></tr></thead>
        <tbody>
          <?php foreach ($eintraege as $z): ?>
            <tr><td class="umbruch-nein"><?= Util::h(Util::datumZeit((string) $z['zeit'])) ?></td>
              <td><?= Util::h((string) $z['akteur'] ?: '—') ?></td>
              <td><a href="<?= Util::attr(App::url('/master/protokoll.php?id=' . (int) $z['id'])) ?>"><?= Util::h(Betreiberlog::text((string) $z['aktion'])) ?></a></td>
              <td><?= schwerePille((string) $z['schwere']) ?></td>
              <td><?= Util::h(Betreiberlog::ERGEBNIS[$z['ergebnis']] ?? (string) $z['ergebnis']) ?></td>
              <td class="klein"><?= Util::h(Util::kuerzen((string) $z['grund'], 80)) ?></td></tr>
          <?php endforeach; ?>
        </tbody>
      </table></div>
      <div class="karte__fuss"><?= App::seitenNavigation($gesamt, 50, $seite, App::url($hier . '&reiter=audit')) ?></div>
    <?php endif; ?>
  </div>
<?php endif; ?>

<?php /* ------------------------------------------------------ Dialoge --- */ ?>

<dialog class="modal" id="modal-support">
  <form method="post" action="<?= Util::attr(App::url('/master/support.php')) ?>">
    <?= Auth::csrfFeld() ?>
    <input type="hidden" name="aktion" value="start"><input type="hidden" name="id" value="<?= $id ?>">
    <div class="modal__kopf"><h2>Als Support öffnen</h2>
      <button type="button" class="rundknopf" data-modal-zu aria-label="Schließen"><?= Icon::svg('x', 17) ?></button></div>
    <div class="modal__koerper">
      <p>Du siehst „<?= Util::h((string) $ws['name']) ?>" mit den Rechten des Inhabers – ohne dessen Passwort.
        Die Sitzung endet nach <?= Plattform::zahl('support_minuten') ?> Minuten von selbst.</p>
      <ul class="klein gedimmt">
        <li>Beginn, Ende und Grund stehen im Audit-Log.</li>
        <li>Im Protokoll der Instanz steht, dass der TeePilot Support da war; jede Änderung ist dort als Support gekennzeichnet.</li>
        <li>Passwort und Schnittstellenschlüssel lassen sich im Support Mode nicht ändern.</li>
      </ul>
      <div class="feld"><label class="feld__label" for="s-grund">Grund (optional)</label>
        <input class="eingabe" id="s-grund" name="grund" maxlength="500" placeholder="z. B. Ticket 1042: Buchungsseite zeigt keine Termine"></div>
    </div>
    <div class="modal__fuss">
      <button type="button" class="btn" data-modal-zu>Abbrechen</button>
      <button type="submit" class="btn btn--primaer"><?= Icon::svg('eye', 15) ?> Support Mode starten</button>
    </div>
  </form>
</dialog>

<?php foreach (Instanzen::ZIELE as $ziel): if ($ziel === $status) { continue; }
  $verb = ['aktiv' => $status === 'archiviert' ? 'Reaktivieren' : 'Aktivieren', 'pausiert' => 'Pausieren',
           'gesperrt' => 'Sperren', 'archiviert' => 'Archivieren'][$ziel]; ?>
<dialog class="modal" id="modal-status-<?= $ziel ?>">
  <form method="post" action="<?= Util::attr(App::url('/master/instanz.php')) ?>">
    <?= Auth::csrfFeld() ?>
    <input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="aktion" value="status">
    <input type="hidden" name="neu" value="<?= $ziel ?>"><input type="hidden" name="erwartet" value="<?= Util::attr($status) ?>">
    <input type="hidden" name="reiter" value="<?= Util::attr($reiter) ?>">
    <div class="modal__kopf"><h2><?= Util::h($verb) ?>: <?= Util::h((string) $ws['name']) ?></h2>
      <button type="button" class="rundknopf" data-modal-zu aria-label="Schließen"><?= Icon::svg('x', 17) ?></button></div>
    <div class="modal__koerper">
      <p>Status danach: <?= Instanzen::statusPille($ziel) ?></p>
      <div class="hinweis hinweis--<?= in_array($ziel, ['gesperrt', 'archiviert'], true) ? 'gefahr' : ($ziel === 'pausiert' ? 'warnung' : 'still') ?> mb-4">
        <?= Icon::svg('info', 17) ?><div class="hinweis__text"><?= Util::h(Instanzen::WIRKUNG[$ziel]) ?></div></div>
      <div class="feld"><label class="feld__label" for="g-<?= $ziel ?>">Grund<?= $ziel === 'aktiv' ? ' (optional)' : '' ?></label>
        <input class="eingabe" id="g-<?= $ziel ?>" name="grund" maxlength="500"<?= $ziel === 'aktiv' ? '' : ' required' ?>></div>
    </div>
    <div class="modal__fuss">
      <button type="button" class="btn" data-modal-zu>Abbrechen</button>
      <button type="submit" class="btn <?= in_array($ziel, ['gesperrt', 'archiviert'], true) ? 'btn--gefahr' : 'btn--primaer' ?>"><?= Util::h($verb) ?></button>
    </div>
  </form>
</dialog>
<?php endforeach; ?>

<?php if ($status === 'archiviert'): ?>
<dialog class="modal" id="modal-loeschen">
  <form method="post" action="<?= Util::attr(App::url('/master/instanz.php')) ?>">
    <?= Auth::csrfFeld() ?>
    <input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="aktion" value="loeschen">
    <div class="modal__kopf"><h2>Endgültig löschen</h2>
      <button type="button" class="rundknopf" data-modal-zu aria-label="Schließen"><?= Icon::svg('x', 17) ?></button></div>
    <div class="modal__koerper">
      <div class="hinweis hinweis--gefahr mb-4"><?= Icon::svg('alert', 17) ?>
        <div class="hinweis__text">Das lässt sich nicht rückgängig machen. Gelöscht werden die Instanz, alle Benutzer, Kunden, Termine,
          Rechnungen, Seiten, Einstellungen und alle hochgeladenen Dateien. Bleiben soll sie nur archiviert? Dann hier abbrechen.</div></div>
      <div class="feld"><label class="feld__label" for="l-name">Zum Bestätigen den Namen genau abtippen: <b><?= Util::h((string) $ws['name']) ?></b></label>
        <input class="eingabe" id="l-name" name="bestaetigung" autocomplete="off" required
               data-name-bestaetigen="#l-knopf" data-soll="<?= Util::attr((string) $ws['name']) ?>"></div>
      <div class="feld"><label class="feld__label" for="l-grund">Grund</label>
        <input class="eingabe" id="l-grund" name="grund" maxlength="500" required placeholder="z. B. Löschung auf Wunsch des Kunden (DSGVO Art. 17)"></div>
    </div>
    <div class="modal__fuss">
      <button type="button" class="btn" data-modal-zu>Abbrechen</button>
      <button type="submit" class="btn btn--gefahr" id="l-knopf"><?= Icon::svg('trash', 15) ?> Endgültig löschen</button>
    </div>
  </form>
</dialog>
<?php endif; ?>

<?php require __DIR__ . '/partials/fuss.php';
