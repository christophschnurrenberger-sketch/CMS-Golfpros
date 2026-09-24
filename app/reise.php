<?php
/**
 * Eine Golfreise – alles, was ein Reisebüro zu einer Reise führt.
 *
 * Reiter statt einer langen Seite: Überblick, Bilder, Hotel & Plätze,
 * Programm, Preise & Buchung, Buchungen. Die ersten fünf sind ein einziges
 * Formular mit einem Speicherknopf – wer zwischen den Reitern wechselt,
 * verliert nichts. Die Buchungen haben eigene Handlungen und stehen
 * außerhalb.
 */
require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/partials/helfer.php';

Auth::fordern('modul.travel');

$id    = App::getInt('id');
$reise = $id > 0 ? Tenant::find('trips', $id) : null;
if ($reise === null) {
    App::melden('Diese Reise gibt es nicht.', 'fehler');
    App::weiter('/app/reisen.php');
}

/* ---------------------------------------------------------- Listen --- */

if (in_array(App::get('export'), ['teilnehmer', 'zimmer'], true)) {
    $art = App::get('export');
    Audit::schreiben('export', 'trip', $id, ($art === 'zimmer' ? 'Zimmerliste ' : 'Teilnehmerliste ') . (string) $reise['titel']);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . ($art === 'zimmer' ? 'zimmerliste-' : 'teilnehmer-')
        . preg_replace('/[^a-z0-9-]/', '', (string) $reise['slug']) . '.csv"');
    echo Trips::csv($reise, $art);
    exit;
}

/*
 * Aus mehrzeiligen Feldern werden Listen: eine Zeile, ein Punkt. Sieben
 * Leistungen tippt man in sieben Zeilen, nicht über sieben Mal „Hinzufügen".
 */
$alsListe = static function (string $text): string {
    $zeilen = [];
    foreach (preg_split('/\r\n|\r|\n/', $text) ?: [] as $z) {
        $z = trim($z);
        if ($z !== '') {
            $zeilen[] = $z;
        }
    }
    return Util::json($zeilen);
};

/** Parallele Listen aus dem Formular zu Einträgen – leere fallen weg. */
$zeilenAus = static function (array $felder, string $pflicht): array {
    $spalten = [];
    foreach ($felder as $feld) {
        $spalten[$feld] = App::postListe($feld);
    }
    $aus = [];
    foreach (array_keys($spalten[$pflicht]) as $i) {
        $z = [];
        foreach ($felder as $feld) {
            $z[$feld] = trim((string) ($spalten[$feld][$i] ?? ''));
        }
        if ($z[$pflicht] !== '') {
            $aus[] = $z;
        }
    }
    return $aus;
};

if (App::istPost()) {
    Auth::csrfFordern();
    $aktion = App::aktion();

    if ($aktion === 'speichern') {
        Auth::fordern('travel.write');
        $bilder = [];
        foreach (App::postListe('bilder') as $b) {
            $b = Trips::pfad((string) $b);
            if ($b !== '' && !in_array($b, $bilder, true)) {
                $bilder[] = $b;
            }
        }
        $plaetze = array_map(static fn ($g) => [
            'name' => $g['gp_name'], 'loecher' => (int) $g['gp_loecher'], 'par' => (int) $g['gp_par'],
            'text' => $g['gp_text'], 'bild' => Trips::pfad($g['gp_bild']),
        ], $zeilenAus(['gp_name', 'gp_loecher', 'gp_par', 'gp_text', 'gp_bild'], 'gp_name'));
        $tage = array_map(static fn ($t) => [
            'titel' => $t['tag_titel'], 'text' => $t['tag_text'], 'bild' => Trips::pfad($t['tag_bild']),
        ], array_filter($zeilenAus(['tag_titel', 'tag_text', 'tag_bild'], 'tag_titel'),
            static fn ($t) => $t['tag_titel'] !== '' || $t['tag_text'] !== ''));
        $extras = array_map(static fn ($x) => [
            'name' => $x['x_name'], 'preis_cent' => Util::centAus($x['x_preis']),
            'je' => $x['x_je'] === 'buchung' ? 'buchung' : 'person',
        ], $zeilenAus(['x_name', 'x_preis', 'x_je'], 'x_name'));
        $fruehBis = App::post('fruehbucher_bis');

        Trips::speichern([
            'titel'            => App::post('titel'),
            'ziel'             => App::post('ziel'),
            'land'             => App::post('land'),
            'kurztext'         => App::post('kurztext'),
            'beschreibung'     => App::postRoh('beschreibung'),
            'highlights'       => $alsListe(App::postRoh('highlights')),
            'status'           => App::post('status', 'geplant'),
            'trainer_id'       => App::postInt('trainer_id'),
            'bild'             => Trips::pfad(App::post('bild')),
            'bilder'           => Util::json($bilder),
            'hotel'            => App::post('hotel'),
            'hotel_sterne'     => App::postInt('hotel_sterne'),
            'hotel_url'        => preg_match('#^https?://#i', App::post('hotel_url')) ? App::post('hotel_url') : '',
            'hotel_text'       => App::postRoh('hotel_text'),
            'hotel_bild'       => Trips::pfad(App::post('hotel_bild')),
            'golfplaetze'      => Util::json($plaetze),
            'programm'         => Util::json(array_values($tage)),
            'start'            => App::post('start') . ' 00:00:00',
            'ende'             => App::post('ende') . ' 00:00:00',
            'anreise'          => array_key_exists(App::post('anreise'), Trips::ANREISE) ? App::post('anreise') : 'eigen',
            'abflug_ort'       => App::post('abflug_ort'),
            'preis_cent'       => Util::centAus(App::post('preis')),
            'ez_zuschlag_cent' => Util::centAus(App::post('ez_zuschlag')),
            'preis_nichtgolfer_cent' => Util::centAus(App::post('preis_nichtgolfer')),
            'anzahlung_cent'   => Util::centAus(App::post('anzahlung')),
            'restzahlung_tage' => max(0, min(180, App::postInt('restzahlung_tage', 30))),
            'fruehbucher_bis'  => preg_match('/^\d{4}-\d{2}-\d{2}$/', $fruehBis) ? $fruehBis . ' 00:00:00' : null,
            'fruehbucher_rabatt_cent' => Util::centAus(App::post('fruehbucher_rabatt')),
            'extras'           => Util::json($extras),
            'leistungen'       => $alsListe(App::postRoh('leistungen')),
            'nicht_enthalten'  => $alsListe(App::postRoh('nicht_enthalten')),
            'hinweise'         => App::postRoh('hinweise'),
            'plaetze'          => max(1, App::postInt('plaetze', 12)),
            'mindest_teilnehmer' => max(0, App::postInt('mindest_teilnehmer')),
            'warteliste'       => App::postBool('warteliste') ? 1 : 0,
        ], $id);
        App::melden('Reise gespeichert.');
        App::weiter('/app/reise.php?id=' . $id . '#' . preg_replace('/[^a-z]/', '', App::post('reiter', 'ueberblick')));
    }

    if ($aktion === 'anmelden') {
        Auth::fordern('travel.write');
        $reisende = [['name' => App::post('name'), 'golfer' => App::post('golfer1', '1') === '1', 'hcp' => App::post('hcp1')]];
        if (trim(App::post('name2')) !== '') {
            $reisende[] = ['name' => App::post('name2'), 'golfer' => App::post('golfer2', '1') === '1', 'hcp' => App::post('hcp2')];
        }
        /* Mit Schlüssel lesen: Die Nummer ist die Stelle in der Liste der Reise. */
        $extrasWahl = [];
        foreach ((array) ($_POST['extra'] ?? []) as $nr => $anzahl) {
            $extrasWahl[(int) $nr] = is_scalar($anzahl) ? (int) $anzahl : 0;
        }
        [$neu, $meldung] = Trips::anmelden($id, [
            'reisende'    => $reisende,
            'email'       => App::post('email'),
            'telefon'     => App::post('telefon'),
            'zimmer'      => App::post('zimmer', 'dz'),
            'extras'      => $extrasWahl,
            'notiz'       => App::post('notiz'),
            'customer_id' => App::postInt('customer_id'),
        ]);
        App::melden($neu === 0 ? $meldung : ($meldung !== '' ? $meldung : 'Buchung erfasst.'), $neu === 0 ? 'fehler' : 'erfolg');
        App::weiter('/app/reise.php?id=' . $id . '#buchungen');
    }

    /* Alles Weitere betrifft eine Buchung – und zwar nur eine dieser Reise.
       Ohne die Prüfung ließe sich mit einer fremden Kennung eine Buchung
       einer anderen Reise umstellen. */
    $buchung = Tenant::find('trip_signups', App::postInt('anmeldung_id'));
    if ($buchung !== null && (int) $buchung['trip_id'] !== $id) {
        $buchung = null;
    }

    if ($aktion === 'anmeldung_status' && $buchung !== null) {
        Auth::fordern('travel.write');
        Trips::statusSetzen((int) $buchung['id'], App::post('status'));
        App::melden('Buchung aktualisiert.');
        App::weiter('/app/reise.php?id=' . $id . '#buchungen');
    }

    if ($aktion === 'bestaetigen' && $buchung !== null) {
        Auth::fordern('travel.write');
        Trips::statusSetzen((int) $buchung['id'], 'bestaetigt');
        $gesendet = (string) $buchung['email'] !== '' && Mail::senden((string) $buchung['email'],
            'Reisebestätigung: ' . (string) $reise['titel'],
            Trips::bestaetigungText(Tenant::find('trip_signups', (int) $buchung['id']) ?? $buchung, $reise));
        App::melden($gesendet ? 'Bestätigt – die Bestätigung ist an ' . $buchung['email'] . ' unterwegs.'
            : 'Bestätigt. Eine E-Mail ging nicht hinaus – bitte die Adresse prüfen.', $gesendet ? 'erfolg' : 'warnung');
        App::weiter('/app/reise.php?id=' . $id . '#buchungen');
    }

    if ($aktion === 'zahlung' && $buchung !== null) {
        Auth::fordern('travel.write');
        Trips::zahlungSetzen((int) $buchung['id'], App::post('was') === 'rest' ? 'rest' : 'anzahlung', App::postBool('bezahlt'));
        App::melden(App::postBool('bezahlt') ? 'Zahlungseingang vermerkt.' : 'Zahlung wieder als offen markiert.');
        App::weiter('/app/reise.php?id=' . $id . '#buchungen');
    }

    if ($aktion === 'loeschen') {
        Auth::fordern('travel.delete');
        Tenant::delete('trips', $id);
        Tenant::deleteWhere('trip_signups', 'trip_id = :t', ['t' => $id]);
        Audit::schreiben('geloescht', 'trip', $id, (string) $reise['titel']);
        App::melden('Reise gelöscht.');
        App::weiter('/app/reisen.php');
    }
    App::weiter('/app/reise.php?id=' . $id);
}

/* ---------------------------------------------------------- Ansicht --- */

$buchungen = Trips::anmeldungen($id);
$belegt    = Trips::belegt($id);
$frei      = Trips::freiePlaetze($reise);
$fehlt     = Trips::fehlend($reise);
$warte     = array_sum(array_map(static fn ($b) => (int) $b['personen'], Trips::anmeldungen($id, 'warteliste')));
$galerie   = Trips::galerie($reise);
$plaetze   = Trips::golfplaetze((string) $reise['golfplaetze']);
$programm  = Trips::programm((string) $reise['programm']);
$extras    = Trips::extras((string) $reise['extras']);
$darf      = Auth::darf('travel.write');
$ro        = $darf ? '' : ' disabled';
$geld      = static fn (int $c): string => $c > 0 ? number_format($c / 100, 2, ',', '') : '';
$offen     = 0;
foreach ($buchungen as $b) {
    if (in_array((string) $b['status'], ['angemeldet', 'bestaetigt'], true)) {
        $offen += ((int) $b['anzahlung_bezahlt'] === 1 ? 0 : (int) $b['anzahlung_cent'])
                + ((int) $b['bezahlt'] === 1 ? 0 : (int) $b['preis_cent'] - (int) $b['anzahlung_cent']);
    }
}

$titel = (string) $reise['titel'];
$unter = trim((string) $reise['ziel'] . ' · ' . (string) $reise['land'], ' ·') . ' · '
       . Util::datum((string) $reise['start']) . '–' . Util::datum((string) $reise['ende'])
       . ' · ' . (int) $reise['naechte'] . ' Nächte';
$brotkrumen = [['Reisen', '/app/reisen.php'], [$titel, null]];
$istOeffentlich = (string) $reise['status'] === Trips::OEFFENTLICH;
$aktionen = '<a class="btn" target="_blank" rel="noopener" href="'
          . Util::attr(Oeffentlich::url('/reise.php', ['r' => (string) $reise['slug']]))
          . '">' . Icon::svg('external', 15) . ($istOeffentlich ? ' Auf der Website' : ' Vorschau') . '</a>';
require __DIR__ . '/partials/kopf.php';

/** Eine wiederholbare Zeile mit Knöpfen zum Verschieben und Entfernen. */
$zeilenKnoepfe = static function () use ($darf): string {
    if (!$darf) {
        return '';
    }
    return '<div class="zeile__knoepfe">'
         . '<button type="button" class="rundknopf rundknopf--klein" data-zeile-hoch title="Nach oben" aria-label="Nach oben">' . Icon::svg('chevron-up', 14) . '</button>'
         . '<button type="button" class="rundknopf rundknopf--klein" data-zeile-runter title="Nach unten" aria-label="Nach unten">' . Icon::svg('chevron-down', 14) . '</button>'
         . '<button type="button" class="rundknopf rundknopf--klein zeile__weg" data-zeile-weg title="Entfernen" aria-label="Entfernen">' . Icon::svg('trash', 14) . '</button>'
         . '</div>';
};
?>

<div class="raster raster--4 mb-5">
  <?= kennzahl('Belegt', $belegt . ' / ' . (int) $reise['plaetze'],
        ['icon' => 'customers', 'fuss' => $frei > 0 ? $frei . ' Plätze frei' : 'ausgebucht']) ?>
  <?= kennzahl('Gebucht', Util::geld(Trips::umsatz($id)),
        ['icon' => 'euro', 'fuss' => count($buchungen) . ' Buchungen']) ?>
  <?= kennzahl('Offene Zahlungen', Util::geld($offen),
        ['icon' => 'clock', 'fuss' => $offen > 0 ? 'Anzahlungen und Restzahlungen' : 'alles bezahlt']) ?>
  <?= kennzahl('Mindestzahl', (int) $reise['mindest_teilnehmer'] > 0 ? (string) (int) $reise['mindest_teilnehmer'] : '—',
        ['icon' => 'target', 'fuss' => $fehlt > 0 ? 'noch ' . $fehlt . ' nötig' : ($warte > 0 ? $warte . ' auf der Warteliste' : 'erreicht')]) ?>
</div>

<div class="reiter reiter--rollend" data-reiter-gruppe="r">
  <button class="reiter__teil ist-aktiv" data-reiter="ueberblick">Überblick</button>
  <button class="reiter__teil" data-reiter="bilder">Bilder <span class="reiter__zahl"><?= count($galerie) + ((string) $reise['bild'] !== '' ? 1 : 0) ?></span></button>
  <button class="reiter__teil" data-reiter="hotel">Hotel &amp; Plätze</button>
  <button class="reiter__teil" data-reiter="programm">Programm</button>
  <button class="reiter__teil" data-reiter="preise">Preise &amp; Buchung</button>
  <button class="reiter__teil" data-reiter="buchungen">Buchungen <span class="reiter__zahl"><?= count($buchungen) ?></span></button>
</div>

<form method="post" id="reise-form" class="reise-editor">
  <?= Auth::csrfFeld() ?>
  <input type="hidden" name="aktion" value="speichern">
  <input type="hidden" name="reiter" value="ueberblick" data-reiter-merken>

  <!-- ====================================================== Überblick -->
  <div data-reiter-feld="ueberblick" data-reiter-gruppe="r">
    <div class="raster raster--haupt-neben">
      <div class="karte">
        <div class="karte__kopf"><h2>Die Reise</h2></div>
        <div class="karte__koerper">
          <div class="feld"><label class="feld__label" for="t-titel">Titel</label>
            <input class="eingabe" id="t-titel" name="titel" required value="<?= Util::attr((string) $reise['titel']) ?>"<?= $ro ?>></div>
          <div class="feld-reihe feld-reihe--2">
            <div class="feld"><label class="feld__label" for="t-ziel">Ziel</label>
              <input class="eingabe" id="t-ziel" name="ziel" placeholder="Son Servera, Mallorca" value="<?= Util::attr((string) $reise['ziel']) ?>"<?= $ro ?>></div>
            <div class="feld"><label class="feld__label" for="t-land">Land</label>
              <input class="eingabe" id="t-land" name="land" placeholder="Spanien" value="<?= Util::attr((string) $reise['land']) ?>"<?= $ro ?>></div>
          </div>
          <div class="feld"><label class="feld__label" for="t-kurz">Ein Satz für Kacheln und Suchmaschinen</label>
            <input class="eingabe" id="t-kurz" name="kurztext" maxlength="255" data-zaehler="z-kurz"
                   placeholder="Sieben Nächte direkt am Platz, vier Greenfees inklusive."
                   value="<?= Util::attr((string) $reise['kurztext']) ?>"<?= $ro ?>>
            <div class="feld__hinweis" id="z-kurz"></div></div>
          <div class="feld"><label class="feld__label" for="t-text">Beschreibung</label>
            <textarea class="eingabe" id="t-text" name="beschreibung" rows="8" data-waechst<?= $ro ?>
                      placeholder="Wohin geht es, wie wird gespielt, für wen ist die Reise gedacht? Leerzeile = neuer Absatz."
            ><?= Util::h((string) $reise['beschreibung']) ?></textarea></div>
          <div class="feld"><label class="feld__label" for="t-high">Highlights</label>
            <textarea class="eingabe" id="t-high" name="highlights" rows="4" data-waechst<?= $ro ?>
                      placeholder="Ein Punkt je Zeile, drei bis sechs:&#10;4 Greenfees auf 3 Meisterschaftsplätzen&#10;Hotel direkt am Meer&#10;Tägliches Training mit dem Pro"
            ><?= Util::h(implode("\n", Trips::zeilen((string) $reise['highlights']))) ?></textarea>
            <div class="feld__hinweis">Stehen auf der Reiseseite ganz oben, mit Haken davor.</div></div>
        </div>
      </div>
      <div class="stapel">
        <div class="karte">
          <div class="karte__kopf"><h2>Sichtbarkeit</h2></div>
          <div class="karte__koerper">
            <div class="feld"><label class="feld__label" for="t-status">Status</label>
              <select id="t-status" name="status"<?= $ro ?>>
                <?php foreach (Trips::STATUS as $k => $n): ?>
                  <option value="<?= $k ?>"<?= (string) $reise['status'] === $k ? ' selected' : '' ?>><?= Util::h($n) ?></option>
                <?php endforeach; ?>
              </select>
              <div class="feld__hinweis">Nur veröffentlichte Reisen stehen im Reisekatalog der Website. Über „Vorschau" siehst du die Seite vorher.</div></div>
            <div class="feld"><label class="feld__label" for="t-trainer">Reiseleitung</label>
              <select id="t-trainer" name="trainer_id"<?= $ro ?>>
                <option value="0">— niemand eingetragen —</option>
                <?php foreach (Tenant::all('users', 'aktiv = 1', [], 'name') as $u): ?>
                  <option value="<?= (int) $u['id'] ?>"<?= (int) $reise['trainer_id'] === (int) $u['id'] ? ' selected' : '' ?>><?= Util::h((string) $u['name']) ?></option>
                <?php endforeach; ?>
              </select></div>
          </div>
        </div>
        <?php if ((string) $reise['bild'] !== ''): ?>
          <div class="karte reise-vorschau">
            <img src="<?= Util::attr(App::url((string) $reise['bild'])) ?>" alt="">
            <div class="karte__koerper">
              <div class="klein gedimmt"><?= Util::h(trim((string) $reise['ziel'] . ' · ' . (string) $reise['land'], ' ·')) ?></div>
              <div class="halbfett"><?= Util::h((string) $reise['titel']) ?></div>
              <div class="klein">ab <?= Util::h(Util::geldKurz(Trips::abPreis($reise))) ?> p. P.</div>
            </div>
          </div>
        <?php endif; ?>
      </div>
    </div>
    <?php if ($darf): ?><div class="reise-editor__fuss"><button class="btn btn--primaer" type="submit">Speichern</button></div><?php endif; ?>
  </div>

  <!-- ========================================================= Bilder -->
  <div data-reiter-feld="bilder" data-reiter-gruppe="r" class="versteckt">
    <div class="raster raster--neben-haupt">
      <div class="karte">
        <div class="karte__kopf"><h2>Titelbild</h2></div>
        <div class="karte__koerper">
          <?= bildfeld('', 'bild', (string) $reise['bild'], ['id' => 't-bild',
                'hinweis' => 'Groß oben auf der Reiseseite und auf jeder Kachel. Querformat, am besten 1600 Pixel breit.']) ?>
        </div>
      </div>
      <div class="karte" data-zeilen>
        <div class="karte__kopf"><h2>Galerie <span class="reiter__zahl" data-zeilen-anzahl><?= count($galerie) ?></span></h2>
          <?php if ($darf): ?>
            <div class="karte__kopf-aktionen"><button type="button" class="btn btn--klein btn--primaer" data-zeile-neu data-bild-sofort data-nur-js hidden>
              <?= Icon::svg('plus', 14) ?> Bild hinzufügen</button></div>
          <?php endif; ?></div>
        <div class="karte__koerper">
          <p class="klein gedimmt mb-4">Hotel, Zimmer, Plätze, Umgebung – fünf bis zwölf Bilder erzählen eine Reise besser als jeder Text.
            Die Reihenfolge hier ist die Reihenfolge auf der Website.</p>
          <div class="galerie-editor" data-zeilen-liste>
            <?php foreach (array_merge($galerie, $darf ? [''] : []) as $i => $b): ?>
              <div class="galerie-editor__teil" data-zeile<?= $b === '' ? ' data-zeile-leer' : '' ?>>
                <?= bildfeld('', 'bilder[]', $b, ['eng' => true]) ?>
                <?= $zeilenKnoepfe() ?>
              </div>
            <?php endforeach; ?>
          </div>
          <p class="klein gedimmt" data-zeilen-leer<?= $galerie !== [] ? ' hidden' : '' ?>>Noch keine Bilder in der Galerie.</p>
          <template data-zeilen-vorlage>
            <div class="galerie-editor__teil" data-zeile><?= bildfeld('', 'bilder[]', '', ['eng' => true]) ?><?= $zeilenKnoepfe() ?></div>
          </template>
        </div>
      </div>
    </div>
    <?php if ($darf): ?><div class="reise-editor__fuss"><button class="btn btn--primaer" type="submit">Speichern</button></div><?php endif; ?>
  </div>

  <!-- ================================================= Hotel & Plätze -->
  <div data-reiter-feld="hotel" data-reiter-gruppe="r" class="versteckt">
    <div class="raster raster--2">
      <div class="karte">
        <div class="karte__kopf"><h2>Hotel</h2></div>
        <div class="karte__koerper">
          <div class="feld-reihe feld-reihe--2">
            <div class="feld"><label class="feld__label" for="t-hotel">Name</label>
              <input class="eingabe" id="t-hotel" name="hotel" placeholder="Hotel Pula Golf Resort" value="<?= Util::attr((string) $reise['hotel']) ?>"<?= $ro ?>></div>
            <div class="feld"><label class="feld__label" for="t-sterne">Sterne</label>
              <select id="t-sterne" name="hotel_sterne"<?= $ro ?>>
                <?php for ($s = 0; $s <= 5; $s++): ?>
                  <option value="<?= $s ?>"<?= (int) $reise['hotel_sterne'] === $s ? ' selected' : '' ?>><?= $s === 0 ? 'keine Angabe' : str_repeat('★', $s) ?></option>
                <?php endfor; ?>
              </select></div>
          </div>
          <div class="feld"><label class="feld__label" for="t-hoteltext">Über das Hotel</label>
            <textarea class="eingabe" id="t-hoteltext" name="hotel_text" rows="4" data-waechst<?= $ro ?>
                      placeholder="Lage, Zimmer, Essen, Wellness – was die Gäste wissen wollen."><?= Util::h((string) $reise['hotel_text']) ?></textarea></div>
          <div class="feld"><label class="feld__label" for="t-hotelurl">Website des Hotels</label>
            <input class="eingabe" id="t-hotelurl" name="hotel_url" placeholder="https://" value="<?= Util::attr((string) $reise['hotel_url']) ?>"<?= $ro ?>></div>
          <?= bildfeld('Bild des Hotels', 'hotel_bild', (string) $reise['hotel_bild'], ['id' => 't-hotelbild']) ?>
        </div>
      </div>
      <div class="karte" data-zeilen>
        <div class="karte__kopf"><h2>Golfplätze <span class="reiter__zahl" data-zeilen-anzahl><?= count($plaetze) ?></span></h2>
          <?php if ($darf): ?>
            <div class="karte__kopf-aktionen"><button type="button" class="btn btn--klein" data-zeile-neu data-nur-js hidden>
              <?= Icon::svg('plus', 14) ?> Platz hinzufügen</button></div>
          <?php endif; ?></div>
        <div class="karte__koerper">
          <div class="stapel" data-zeilen-liste>
            <?php
            $platzZeile = static function (array $g, bool $leer) use ($zeilenKnoepfe, $ro): string {
                ob_start(); ?>
                <div class="zeilenkarte" data-zeile<?= $leer ? ' data-zeile-leer' : '' ?>>
                  <div class="zeilenkarte__kopf"><span class="versal gedimmt-2">Platz <span data-zeile-nr></span></span><?= $zeilenKnoepfe() ?></div>
                  <div class="feld-reihe feld-reihe--3">
                    <div class="feld" style="flex:2"><label class="feld__label">Name</label>
                      <input class="eingabe" name="gp_name[]" placeholder="Golf Son Servera" value="<?= Util::attr($g['name']) ?>"<?= $ro ?>></div>
                    <div class="feld"><label class="feld__label">Löcher</label>
                      <input class="eingabe" type="number" min="0" max="36" name="gp_loecher[]" value="<?= $g['loecher'] ? (int) $g['loecher'] : 18 ?>"<?= $ro ?>></div>
                    <div class="feld"><label class="feld__label">Par</label>
                      <input class="eingabe" type="number" min="0" max="144" name="gp_par[]" value="<?= $g['par'] ? (int) $g['par'] : '' ?>" placeholder="72"<?= $ro ?>></div>
                  </div>
                  <div class="feld"><label class="feld__label">Was ihn ausmacht</label>
                    <textarea class="eingabe" name="gp_text[]" rows="2" data-waechst placeholder="Parkland zwischen Pinien, breite Fairways, schnelle Grüns."<?= $ro ?>><?= Util::h($g['text']) ?></textarea></div>
                  <?= bildfeld('Bild', 'gp_bild[]', $g['bild']) ?>
                </div>
                <?php return (string) ob_get_clean();
            };
            foreach ($plaetze as $g) {
                echo $platzZeile($g, false);
            }
            if ($darf) {
                echo $platzZeile(['name' => '', 'loecher' => 18, 'par' => 0, 'text' => '', 'bild' => ''], true);
            } ?>
          </div>
          <p class="klein gedimmt" data-zeilen-leer<?= $plaetze !== [] ? ' hidden' : '' ?>>Welche Plätze werden gespielt? Mit Bild und zwei Sätzen wird daraus ein Grund zu buchen.</p>
          <template data-zeilen-vorlage><?= $platzZeile(['name' => '', 'loecher' => 18, 'par' => 0, 'text' => '', 'bild' => ''], false) ?></template>
        </div>
      </div>
    </div>
    <?php if ($darf): ?><div class="reise-editor__fuss"><button class="btn btn--primaer" type="submit">Speichern</button></div><?php endif; ?>
  </div>

  <!-- ======================================================= Programm -->
  <div data-reiter-feld="programm" data-reiter-gruppe="r" class="versteckt">
    <div class="karte" data-zeilen style="max-width:920px">
      <div class="karte__kopf"><h2>Tag für Tag</h2>
        <?php if ($darf): ?>
          <div class="karte__kopf-aktionen"><button type="button" class="btn btn--klein" data-zeile-neu data-nur-js hidden>
            <?= Icon::svg('plus', 14) ?> Tag hinzufügen</button></div>
        <?php endif; ?></div>
      <div class="karte__koerper">
        <div class="stapel" data-zeilen-liste>
          <?php
          $tagZeile = static function (array $t, bool $leer) use ($zeilenKnoepfe, $ro): string {
              ob_start(); ?>
              <div class="zeilenkarte zeilenkarte--tag" data-zeile<?= $leer ? ' data-zeile-leer' : '' ?>>
                <div class="zeilenkarte__kopf"><span class="tag-marke">Tag <span data-zeile-nr></span></span><?= $zeilenKnoepfe() ?></div>
                <div class="zeilenkarte__zwei">
                  <div>
                    <div class="feld"><label class="feld__label">Überschrift</label>
                      <input class="eingabe" name="tag_titel[]" placeholder="Anreise und Einspielen" value="<?= Util::attr($t['titel']) ?>"<?= $ro ?>></div>
                    <div class="feld"><label class="feld__label">Was passiert</label>
                      <textarea class="eingabe" name="tag_text[]" rows="2" data-waechst placeholder="Transfer zum Hotel, 9 Löcher am Nachmittag, abends gemeinsames Essen."<?= $ro ?>><?= Util::h($t['text']) ?></textarea></div>
                  </div>
                  <?= bildfeld('Bild (optional)', 'tag_bild[]', $t['bild']) ?>
                </div>
              </div>
              <?php return (string) ob_get_clean();
          };
          foreach ($programm as $t) {
              echo $tagZeile($t, false);
          }
          if ($darf) {
              echo $tagZeile(['titel' => '', 'text' => '', 'bild' => ''], true);
          } ?>
        </div>
        <p class="klein gedimmt" data-zeilen-leer<?= $programm !== [] ? ' hidden' : '' ?>>Noch kein Programm. Ein Tag je Eintrag – auf der Website wird daraus ein Ablauf zum Durchscrollen.</p>
        <template data-zeilen-vorlage><?= $tagZeile(['titel' => '', 'text' => '', 'bild' => ''], false) ?></template>
      </div>
    </div>
    <?php if ($darf): ?><div class="reise-editor__fuss"><button class="btn btn--primaer" type="submit">Speichern</button></div><?php endif; ?>
  </div>

  <!-- ================================================= Preise & Buchung -->
  <div data-reiter-feld="preise" data-reiter-gruppe="r" class="versteckt">
    <div class="raster raster--2">
      <div class="stapel">
        <div class="karte">
          <div class="karte__kopf"><h2>Termin und Anreise</h2></div>
          <div class="karte__koerper">
            <div class="feld-reihe feld-reihe--2">
              <div class="feld"><label class="feld__label" for="t-start">Anreise</label>
                <input class="eingabe" id="t-start" type="date" name="start" required value="<?= Util::attr(substr((string) $reise['start'], 0, 10)) ?>"<?= $ro ?>></div>
              <div class="feld"><label class="feld__label" for="t-ende">Abreise</label>
                <input class="eingabe" id="t-ende" type="date" name="ende" required value="<?= Util::attr(substr((string) $reise['ende'], 0, 10)) ?>"<?= $ro ?>>
                <div class="feld__hinweis">Die Nächte rechnet das System daraus aus.</div></div>
            </div>
            <div class="feld-reihe feld-reihe--2">
              <div class="feld"><label class="feld__label" for="t-anreise">Anreiseart</label>
                <select id="t-anreise" name="anreise"<?= $ro ?>>
                  <?php foreach (Trips::ANREISE as $k => $n): ?>
                    <option value="<?= Util::attr($k) ?>"<?= (string) $reise['anreise'] === $k ? ' selected' : '' ?>><?= Util::h($n) ?></option>
                  <?php endforeach; ?>
                </select></div>
              <div class="feld"><label class="feld__label" for="t-abflug">Abflug- oder Abfahrtsort</label>
                <input class="eingabe" id="t-abflug" name="abflug_ort" placeholder="Stuttgart" value="<?= Util::attr((string) $reise['abflug_ort']) ?>"<?= $ro ?>></div>
            </div>
            <div class="feld-reihe feld-reihe--3">
              <div class="feld"><label class="feld__label" for="t-plaetze">Plätze</label>
                <input class="eingabe" id="t-plaetze" type="number" name="plaetze" min="1" value="<?= (int) $reise['plaetze'] ?>"<?= $ro ?>></div>
              <div class="feld"><label class="feld__label" for="t-mindest">Mindestteilnehmer</label>
                <input class="eingabe" id="t-mindest" type="number" name="mindest_teilnehmer" min="0" value="<?= (int) $reise['mindest_teilnehmer'] ?>"<?= $ro ?>></div>
              <div class="feld"><label class="haken" style="margin-top:28px">
                <input type="checkbox" name="warteliste" value="1"<?= (int) $reise['warteliste'] === 1 ? ' checked' : '' ?><?= $ro ?>>
                <span class="haken__text">Warteliste</span></label></div>
            </div>
          </div>
        </div>

        <div class="karte">
          <div class="karte__kopf"><h2>Preise je Person</h2></div>
          <div class="karte__koerper">
            <?php $w = Util::h(Tenant::waehrung()); ?>
            <div class="feld-reihe feld-reihe--2">
              <div class="feld"><label class="feld__label" for="t-preis">Golfer im Doppelzimmer</label>
                <div class="eingabe-gruppe"><input class="eingabe" id="t-preis" name="preis" value="<?= $geld((int) $reise['preis_cent']) ?>"<?= $ro ?>><span class="eingabe-gruppe__anhang"><?= $w ?></span></div></div>
              <div class="feld"><label class="feld__label" for="t-ng">Nichtgolfer</label>
                <div class="eingabe-gruppe"><input class="eingabe" id="t-ng" name="preis_nichtgolfer" placeholder="wie Golfer" value="<?= $geld((int) $reise['preis_nichtgolfer_cent']) ?>"<?= $ro ?>><span class="eingabe-gruppe__anhang"><?= $w ?></span></div>
                <div class="feld__hinweis">Begleitpersonen ohne Greenfees. Leer = gleicher Preis.</div></div>
            </div>
            <div class="feld-reihe feld-reihe--2">
              <div class="feld"><label class="feld__label" for="t-ez">Einzelzimmerzuschlag</label>
                <div class="eingabe-gruppe"><input class="eingabe" id="t-ez" name="ez_zuschlag" value="<?= $geld((int) $reise['ez_zuschlag_cent']) ?>"<?= $ro ?>><span class="eingabe-gruppe__anhang"><?= $w ?></span></div></div>
              <div class="feld"><label class="feld__label" for="t-anzahlung">Anzahlung</label>
                <div class="eingabe-gruppe"><input class="eingabe" id="t-anzahlung" name="anzahlung" placeholder="keine" value="<?= $geld((int) $reise['anzahlung_cent']) ?>"<?= $ro ?>><span class="eingabe-gruppe__anhang"><?= $w ?></span></div></div>
            </div>
            <div class="feld"><label class="feld__label" for="t-rest">Restzahlung fällig</label>
              <div class="eingabe-gruppe"><input class="eingabe" id="t-rest" type="number" min="0" max="180" name="restzahlung_tage" value="<?= (int) $reise['restzahlung_tage'] ?>"<?= $ro ?>><span class="eingabe-gruppe__anhang">Tage vor Anreise</span></div></div>
            <hr>
            <div class="versal gedimmt-2 mb-3">Frühbucher</div>
            <div class="feld-reihe feld-reihe--2">
              <div class="feld"><label class="feld__label" for="t-fb-bis">Buchbar bis</label>
                <input class="eingabe" id="t-fb-bis" type="date" name="fruehbucher_bis" value="<?= Util::attr(substr((string) $reise['fruehbucher_bis'], 0, 10)) ?>"<?= $ro ?>></div>
              <div class="feld"><label class="feld__label" for="t-fb">Rabatt je Person</label>
                <div class="eingabe-gruppe"><input class="eingabe" id="t-fb" name="fruehbucher_rabatt" placeholder="kein Rabatt" value="<?= $geld((int) $reise['fruehbucher_rabatt_cent']) ?>"<?= $ro ?>><span class="eingabe-gruppe__anhang"><?= $w ?></span></div></div>
            </div>
            <?php if (Trips::istFruehbucher($reise)): ?>
              <div class="hinweis hinweis--erfolg"><?= Icon::svg('check', 16) ?>
                <div class="hinweis__text klein">Frühbucherpreis gilt gerade: ab <?= Util::h(Util::geld(Trips::abPreis($reise))) ?> p. P.</div></div>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <div class="stapel">
        <div class="karte" data-zeilen>
          <div class="karte__kopf"><h2>Zusatzleistungen</h2>
            <?php if ($darf): ?>
              <div class="karte__kopf-aktionen"><button type="button" class="btn btn--klein" data-zeile-neu data-nur-js hidden>
                <?= Icon::svg('plus', 14) ?> Leistung</button></div>
            <?php endif; ?></div>
          <div class="karte__koerper">
            <p class="klein gedimmt mb-3">Wählbar bei der Buchung: Leihschläger, Transfer, Zusatzrunde, Verlängerungsnacht …</p>
            <div class="stapel stapel--eng" data-zeilen-liste>
              <?php
              $extraZeile = static function (array $x, bool $leer) use ($zeilenKnoepfe, $ro, $geld, $w): string {
                  ob_start(); ?>
                  <div class="extra-zeile" data-zeile<?= $leer ? ' data-zeile-leer' : '' ?>>
                    <input class="eingabe" name="x_name[]" placeholder="Leihschläger" aria-label="Leistung" value="<?= Util::attr($x['name']) ?>"<?= $ro ?>>
                    <div class="eingabe-gruppe"><input class="eingabe" name="x_preis[]" placeholder="0,00" aria-label="Preis" value="<?= $geld((int) $x['preis_cent']) ?>"<?= $ro ?>><span class="eingabe-gruppe__anhang"><?= $w ?></span></div>
                    <select name="x_je[]" aria-label="Berechnung"<?= $ro ?>>
                      <?php foreach (Trips::EXTRA_JE as $k => $n): ?>
                        <option value="<?= $k ?>"<?= $x['je'] === $k ? ' selected' : '' ?>><?= $n ?></option>
                      <?php endforeach; ?>
                    </select>
                    <?= $zeilenKnoepfe() ?>
                  </div>
                  <?php return (string) ob_get_clean();
              };
              foreach ($extras as $x) {
                  echo $extraZeile($x, false);
              }
              if ($darf) {
                  echo $extraZeile(['name' => '', 'preis_cent' => 0, 'je' => 'person'], true);
              } ?>
            </div>
            <p class="klein gedimmt" data-zeilen-leer<?= $extras !== [] ? ' hidden' : '' ?>>Keine Zusatzleistungen.</p>
            <template data-zeilen-vorlage><?= $extraZeile(['name' => '', 'preis_cent' => 0, 'je' => 'person'], false) ?></template>
          </div>
        </div>

        <div class="karte">
          <div class="karte__kopf"><h2>Leistungen</h2></div>
          <div class="karte__koerper">
            <div class="feld"><label class="feld__label" for="t-leistungen">Im Preis enthalten</label>
              <textarea class="eingabe" id="t-leistungen" name="leistungen" rows="5" data-waechst<?= $ro ?>
                        placeholder="Eine Leistung je Zeile:&#10;7 Nächte im Doppelzimmer&#10;Halbpension&#10;4 Greenfees"
              ><?= Util::h(implode("\n", Trips::zeilen((string) $reise['leistungen']))) ?></textarea></div>
            <div class="feld"><label class="feld__label" for="t-nicht">Nicht enthalten</label>
              <textarea class="eingabe" id="t-nicht" name="nicht_enthalten" rows="4" data-waechst<?= $ro ?>
                        placeholder="Eine Zeile je Punkt:&#10;Anreise&#10;Reiserücktrittsversicherung"
              ><?= Util::h(implode("\n", Trips::zeilen((string) $reise['nicht_enthalten']))) ?></textarea></div>
            <div class="feld"><label class="feld__label" for="t-hinweise">Hinweise zur Buchung</label>
              <textarea class="eingabe" id="t-hinweise" name="hinweise" rows="4" data-waechst<?= $ro ?>
                        placeholder="Stornobedingungen, Reiseveranstalter, Pass und Einreise, Reisebedingungen …"
              ><?= Util::h((string) $reise['hinweise']) ?></textarea>
              <div class="feld__hinweis">Stehen direkt über dem Buchungsformular. Wer Flug und Hotel zusammen verkauft,
                ist in der Regel Reiseveranstalter einer Pauschalreise – mit Formblatt und Absicherung der Anzahlungen.
                Das klärt am besten die Steuer- oder Rechtsberatung.</div></div>
          </div>
        </div>
      </div>
    </div>
    <?php if ($darf): ?><div class="reise-editor__fuss"><button class="btn btn--primaer" type="submit">Speichern</button></div><?php endif; ?>
  </div>
</form>

<!-- ======================================================= Buchungen -->
<div data-reiter-feld="buchungen" data-reiter-gruppe="r" class="versteckt" id="buchungen">
  <div class="karte">
    <div class="karte__kopf"><h2>Buchungen</h2>
      <div class="karte__kopf-aktionen">
        <a class="btn btn--klein" href="<?= Util::attr(App::url('/app/reise.php?id=' . $id . '&export=teilnehmer')) ?>">
          <?= Icon::svg('download', 14) ?> Teilnehmerliste</a>
        <a class="btn btn--klein" href="<?= Util::attr(App::url('/app/reise.php?id=' . $id . '&export=zimmer')) ?>">
          <?= Icon::svg('download', 14) ?> Zimmerliste</a>
        <?php if ($darf): ?>
          <button class="btn btn--klein btn--primaer" data-modal-auf="modal-buchung"><?= Icon::svg('plus', 14) ?> Buchung erfassen</button>
        <?php endif; ?>
      </div></div>
    <?php if ($buchungen === []): ?>
      <div class="karte__koerper">
        <?= leerzustand('globe', 'Noch keine Buchung', 'Buchungen über die Website landen hier – mit Reisenden, Zimmer, Zusatzleistungen und Zahlungsplan. Von Hand erfassen geht über „Buchung erfassen".') ?>
      </div>
    <?php else: ?>
      <div class="tabelle-huelle"><table class="tabelle buchungstabelle">
        <thead><tr><th>Reisende</th><th>Zimmer</th><th class="zahl">Preis</th><th>Anzahlung</th><th>Restzahlung</th><th>Status</th><th class="aktionen"><span class="nur-lesbar">Aktionen</span></th></tr></thead>
        <tbody>
          <?php foreach ($buchungen as $b):
            $leute = Trips::reisende($b);
            $bExtras = (array) Util::ausJson((string) ($b['extras'] ?? ''), []);
            $aktiv = in_array((string) $b['status'], ['angemeldet', 'bestaetigt'], true);
            $rest = (int) $b['preis_cent'] - (int) $b['anzahlung_cent']; ?>
            <tr class="<?= $aktiv ? '' : 'ist-blass' ?>">
              <td>
                <?php foreach ($leute as $n => $r): ?>
                  <div class="<?= $n === 0 ? 'halbfett' : 'klein' ?>"><?= Util::h($r['name']) ?>
                    <?php if (!$r['golfer']): ?><span class="pille pille--klein">Nichtgolfer</span><?php elseif ($r['hcp'] !== ''): ?><span class="winzig gedimmt">HCP <?= Util::h($r['hcp']) ?></span><?php endif; ?></div>
                <?php endforeach; ?>
                <div class="winzig gedimmt"><?= Util::h((string) $b['email']) ?><?= (string) $b['telefon'] !== '' ? ' · ' . Util::h((string) $b['telefon']) : '' ?></div>
                <?php foreach ($bExtras as $x): ?>
                  <div class="winzig">+ <?= (int) ($x['anzahl'] ?? 1) ?> × <?= Util::h((string) ($x['name'] ?? '')) ?></div>
                <?php endforeach; ?>
                <?php if ((string) $b['notiz'] !== ''): ?><div class="winzig gedimmt mt-2">„<?= Util::h((string) $b['notiz']) ?>"</div><?php endif; ?>
              </td>
              <td><?= Util::h(Trips::ZIMMER[(string) $b['zimmer']] ?? (string) $b['zimmer']) ?><div class="winzig gedimmt"><?= (int) $b['personen'] ?> <?= (int) $b['personen'] === 1 ? 'Person' : 'Personen' ?></div></td>
              <td class="zahl"><?= Util::h(Util::geld((int) $b['preis_cent'])) ?>
                <?php if ((int) $b['rabatt_cent'] > 0): ?><div class="winzig gedimmt">Frühbucher −<?= Util::h(Util::geldKurz((int) $b['rabatt_cent'])) ?></div><?php endif; ?></td>
              <?php foreach ([['anzahlung', (int) $b['anzahlung_cent'], (int) $b['anzahlung_bezahlt'] === 1, 'bei Bestätigung'],
                              ['rest', $rest, (int) $b['bezahlt'] === 1, $b['rest_faellig'] ? 'bis ' . Util::datum((string) $b['rest_faellig']) : '']] as [$was, $betrag, $bez, $wann]): ?>
                <td>
                  <?php if ($betrag <= 0): ?><span class="gedimmt">—</span>
                  <?php else: ?>
                    <form method="post" class="zahlung">
                      <?= Auth::csrfFeld() ?>
                      <input type="hidden" name="aktion" value="zahlung">
                      <input type="hidden" name="anmeldung_id" value="<?= (int) $b['id'] ?>">
                      <input type="hidden" name="was" value="<?= $was ?>">
                      <input type="hidden" name="bezahlt" value="<?= $bez ? '0' : '1' ?>">
                      <button type="submit" class="zahlung__knopf<?= $bez ? ' ist-bezahlt' : '' ?>"<?= $darf && $aktiv ? '' : ' disabled' ?>
                              title="<?= $bez ? 'Als offen markieren' : 'Als bezahlt vermerken' ?>">
                        <?= Icon::svg($bez ? 'check' : 'clock', 14) ?> <?= Util::h(Util::geldKurz($betrag)) ?></button>
                    </form>
                    <div class="winzig gedimmt"><?= $bez ? 'bezahlt' : Util::h($wann) ?></div>
                  <?php endif; ?>
                </td>
              <?php endforeach; ?>
              <td>
                <form method="post">
                  <?= Auth::csrfFeld() ?>
                  <input type="hidden" name="aktion" value="anmeldung_status">
                  <input type="hidden" name="anmeldung_id" value="<?= (int) $b['id'] ?>">
                  <select name="status" aria-label="Status" onchange="this.form.submit()"<?= $darf ? '' : ' disabled' ?>>
                    <?php foreach (Trips::BUCHUNGSSTATUS as $k => $n): ?>
                      <option value="<?= $k ?>"<?= (string) $b['status'] === $k ? ' selected' : '' ?>><?= Util::h($n) ?></option>
                    <?php endforeach; ?>
                  </select>
                  <noscript><button class="btn btn--klein" type="submit">Setzen</button></noscript>
                </form>
                <div class="winzig gedimmt mt-2"><?= Util::h(Util::datum((string) $b['erstellt'])) ?></div>
              </td>
              <td class="aktionen">
                <?php if ($darf && in_array((string) $b['status'], ['angemeldet', 'warteliste'], true)): ?>
                  <form method="post" data-bestaetigen="Buchung bestätigen und die Bestätigung mit Zahlungsplan an <?= Util::attr((string) $b['email']) ?> schicken?">
                    <?= Auth::csrfFeld() ?>
                    <input type="hidden" name="aktion" value="bestaetigen">
                    <input type="hidden" name="anmeldung_id" value="<?= (int) $b['id'] ?>">
                    <button class="btn btn--klein btn--primaer" type="submit"><?= Icon::svg('mail', 14) ?> Bestätigen</button>
                  </form>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table></div>
    <?php endif; ?>
  </div>

  <?php if (Auth::darf('travel.delete')): ?>
    <form method="post" class="mt-5" data-bestaetigen="Diese Reise mit allen Buchungen wirklich löschen?">
      <?= Auth::csrfFeld() ?>
      <input type="hidden" name="aktion" value="loeschen">
      <button class="btn btn--gefahr-text btn--klein" type="submit"><?= Icon::svg('trash', 14) ?> Reise löschen</button>
    </form>
  <?php endif; ?>
</div>

<?php if ($darf): ?>
<dialog class="modal" id="modal-buchung">
  <form method="post">
    <?= Auth::csrfFeld() ?>
    <input type="hidden" name="aktion" value="anmelden">
    <div class="modal__kopf"><h2>Buchung erfassen</h2>
      <button type="button" class="rundknopf" data-modal-zu aria-label="Schließen"><?= Icon::svg('x', 17) ?></button></div>
    <div class="modal__koerper">
      <?php foreach ([1, 2] as $n): ?>
        <div class="feld-reihe feld-reihe--3">
          <div class="feld" style="flex:2"><label class="feld__label" for="b-name<?= $n ?>"><?= $n === 1 ? 'Reisende Person' : 'Zweite Person (optional)' ?></label>
            <input class="eingabe" id="b-name<?= $n ?>" name="<?= $n === 1 ? 'name' : 'name2' ?>"<?= $n === 1 ? ' required autofocus' : '' ?>></div>
          <div class="feld"><label class="feld__label" for="b-golf<?= $n ?>">Spielt</label>
            <select id="b-golf<?= $n ?>" name="golfer<?= $n ?>"><option value="1">Golf</option><option value="0">nicht</option></select></div>
          <div class="feld"><label class="feld__label" for="b-hcp<?= $n ?>">HCP</label>
            <input class="eingabe" id="b-hcp<?= $n ?>" name="hcp<?= $n ?>"></div>
        </div>
      <?php endforeach; ?>
      <div class="feld-reihe feld-reihe--3">
        <div class="feld"><label class="feld__label" for="b-mail">E-Mail</label>
          <input class="eingabe" id="b-mail" type="email" name="email"></div>
        <div class="feld"><label class="feld__label" for="b-tel">Telefon</label>
          <input class="eingabe" id="b-tel" type="tel" name="telefon"></div>
        <div class="feld"><label class="feld__label" for="b-zimmer">Zimmer</label>
          <select id="b-zimmer" name="zimmer">
            <?php foreach (Trips::ZIMMER as $k => $n): ?><option value="<?= $k ?>"><?= Util::h($n) ?></option><?php endforeach; ?>
          </select></div>
      </div>
      <?php if ($extras !== []): ?>
        <div class="feld"><span class="feld__label">Zusatzleistungen</span>
          <div class="stapel stapel--eng">
            <?php foreach ($extras as $nr => $x): ?>
              <label class="reihe reihe--eng klein">
                <select name="extra[<?= $nr ?>]" style="width:auto">
                  <?php for ($a = 0; $a <= ($x['je'] === 'buchung' ? 1 : 2); $a++): ?><option value="<?= $a ?>"><?= $a ?></option><?php endfor; ?>
                </select>
                <span><?= Util::h($x['name']) ?> · <?= Util::h(Util::geldKurz($x['preis_cent'])) ?> <?= Util::h(Trips::EXTRA_JE[$x['je']]) ?></span>
              </label>
            <?php endforeach; ?>
          </div></div>
      <?php endif; ?>
      <div class="feld"><label class="feld__label" for="b-notiz">Anmerkung</label>
        <input class="eingabe" id="b-notiz" name="notiz"></div>
      <div class="feld__hinweis">Preis, Frühbucherrabatt, Anzahlung und Restzahlung rechnet das System aus.</div>
    </div>
    <div class="modal__fuss"><div class="fueller"></div>
      <button type="button" class="btn" data-modal-zu>Abbrechen</button>
      <button type="submit" class="btn btn--primaer">Erfassen</button></div>
  </form>
</dialog>
<?php endif; ?>

<script>
/* Welcher Reiter war offen? Nach dem Speichern soll man dort weitermachen. */
document.addEventListener('click', function (e) {
  var t = e.target.closest('[data-reiter-gruppe="r"] [data-reiter]');
  var f = document.querySelector('[data-reiter-merken]');
  if (t && f) f.value = t.dataset.reiter;
});
</script>

<?php require __DIR__ . '/partials/fuss.php'; ?>
