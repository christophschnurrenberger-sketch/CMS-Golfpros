<?php
/**
 * Konto & Abrechnung – was diese Instanz mit TeePilot verbindet.
 *
 * Der Vertrag, die Rechnungen von TeePilot und die Anschrift, auf die sie
 * lauten. Nicht zu verwechseln mit „Rechnungen" im Menü: Das sind die
 * Rechnungen des Pros an seine Kunden. Hier stehen die an ihn.
 *
 * Sehen darf die Seite, wer die allgemeinen Einstellungen sieht –
 * Inhaber, Administration, Head Pro. Der Trainer nicht; er bekommt auch
 * die Meldung an der Glocke nicht (`Notify` filtert beim Lesen).
 *
 * Alles hier ist gelesen, nichts geschrieben: Rechnungen sind nach dem
 * Ausstellen unveränderlich, und die Rechnungsanschrift pflegt TeePilot.
 * Die Instanz kommt ausschließlich aus der Sitzung, nie aus der Adresse.
 */
require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/partials/helfer.php';

Auth::fordern('modul.settings');
Auth::fordern('settings.allgemein');

$ws         = Tenant::id();
$heute      = date('Y-m-d');
$vertrag    = Abos::aktuell($ws);
$rechnungen = Betreiberrechnungen::fuerInstanz($ws);
$nummern    = array_column($rechnungen, 'nummer', 'id');
$offen      = Betreiberrechnungen::offenFuerInstanz($ws);
$ueber      = array_values(array_filter($offen, static fn (array $r) => substr((string) $r['faellig'], 0, 10) < $heute));
$summeOffen = array_sum(array_map(static fn (array $r) => (int) $r['brutto_cent'], $offen));
[$anschrift, $quelle, $quelleNummer] = Betreiberrechnungen::anschriftFuerInstanz($ws);
$luecken    = Betreiberrechnungen::empfaengerLuecken($anschrift);
$kontakt    = trim((string) Plattform::einstellung('rg_email'));

/*
 * Wer die Seite geöffnet hat, hat gesehen, was die Meldungen sagen
 * wollten – die Glocke muss es nicht weiter anzeigen. Im Support Mode
 * nicht: Da schaut jemand anderes nach, und der Inhaber soll seine neue
 * Rechnung trotzdem an der Glocke finden.
 */
if (!Support::vermerkt()) {
    Notify::alleGelesen(Auth::id(), 'teepilot');
}

/* In Kalendertagen gezählt, nicht in 24-Stunden-Stücken ab jetzt: Am
   Nachmittag des 23. ist der 24. „morgen", nicht „in 0 Tagen". */
$tageBis = static fn (string $faellig): int
    => (int) round((strtotime(substr($faellig, 0, 10)) - strtotime($heute)) / 86400);
$wannFaellig = static function (string $faellig) use ($tageBis): string {
    $tage = $tageBis($faellig);
    return match (true) {
        $tage < -1  => 'seit ' . -$tage . ' Tagen überfällig',
        $tage === -1 => 'seit gestern überfällig',
        $tage === 0 => 'heute fällig',
        $tage === 1 => 'morgen fällig',
        default     => 'in ' . $tage . ' Tagen fällig',
    };
};

$titel = 'Konto & Abrechnung';
$unter = 'Dein Vertrag mit ' . Marke::NAME . ' und die Rechnungen dazu.';
$brotkrumen = [['Einstellungen', '/app/einstellungen.php'], ['Konto & Abrechnung', null]];
$aktionen = '<a class="btn" href="' . App::url('/app/tarif.php') . '">' . Icon::svg('layers', 15) . ' Tarif &amp; Module</a>';
require __DIR__ . '/partials/kopf.php';
?>

<?php if ($ueber !== []): ?>
  <div class="hinweis hinweis--gefahr mb-4" role="status">
    <?= Icon::svg('alert', 17) ?>
    <div class="hinweis__text">
      <span class="hinweis__titel"><?= count($ueber) === 1
          ? 'Die Rechnung ' . Util::h((string) $ueber[0]['nummer']) . ' ist überfällig'
          : count($ueber) . ' Rechnungen sind überfällig' ?></span>
      Bitte überweise den offenen Betrag<?= $kontakt !== '' ? ' oder melde dich unter <a href="mailto:'
          . Util::attr($kontakt) . '">' . Util::h($kontakt) . '</a>, falls die Zahlung schon unterwegs ist' : '' ?>.
    </div>
  </div>
<?php endif; ?>

<div class="raster raster--3 mb-5">
  <?= kennzahl('Dein Paket', Module::planName(Tenant::plan()), [
        'icon' => 'layers',
        'fuss' => Util::h(Abos::beschreibung($vertrag, 'Keine Vertragsdaten hinterlegt')),
        'url'  => '/app/tarif.php',
      ]) ?>
  <?= kennzahl('Offen', Util::geld($summeOffen), [
        'icon' => 'euro',
        'fuss' => $offen !== []
            ? count($offen) . ' ' . (count($offen) === 1 ? 'Rechnung' : 'Rechnungen') . ' noch nicht bezahlt'
            : ($rechnungen !== [] ? 'alles bezahlt' : 'noch keine Rechnung'),
      ]) ?>
  <?= kennzahl('Nächste Fälligkeit', $offen !== [] ? Util::datum((string) $offen[0]['faellig']) : '—', [
        'icon' => 'clock',
        'fuss' => $offen !== [] ? Util::h($wannFaellig((string) $offen[0]['faellig'])) : 'nichts fällig',
      ]) ?>
</div>

<div class="raster raster--haupt-neben">
  <?= karteAuf('Rechnungen von ' . Marke::NAME, $rechnungen !== []
        ? '<span class="klein gedimmt">' . count($rechnungen) . ' ' . (count($rechnungen) === 1 ? 'Rechnung' : 'Rechnungen') . '</span>' : '') ?>
    <?php if ($rechnungen === []): ?>
      <div class="karte__koerper">
        <?= leerzustand('invoices', 'Noch keine Rechnung',
              'Sobald ' . Marke::NAME . ' dir eine Rechnung stellt, steht sie hier – und du bekommst eine Meldung an der Glocke oben.') ?>
      </div>
    <?php else: ?>
      <div class="tabelle-huelle"><table class="tabelle tabelle--eng tabelle--sprung">
        <thead><tr><th>Nummer</th><th>Datum</th><th>Zeitraum</th><th class="zahl">Betrag</th><th>Status</th><th class="aktionen"><span class="nur-lesbar">PDF</span></th></tr></thead>
        <tbody>
          <?php foreach ($rechnungen as $tr):
            $istOffen = (string) $tr['status'] === 'offen'; ?>
            <tr id="r<?= (int) $tr['id'] ?>">
              <td><span class="mono"><?= Util::h((string) $tr['nummer']) ?></span>
                <?php if ((string) $tr['art'] === 'storno'): ?>
                  <div class="winzig gedimmt">Storno zu <?= Util::h((string) ($nummern[(int) $tr['bezug_id']] ?? '—')) ?></div>
                <?php endif; ?></td>
              <td><?= Util::h(Util::datum((string) $tr['datum'])) ?></td>
              <td class="klein"><?= Util::h(Betreiberrechnungen::zeitraum($tr)) ?></td>
              <td class="zahl"><?= Util::h(Util::geld((int) $tr['brutto_cent'])) ?></td>
              <td><?= Betreiberrechnungen::statusPille((string) $tr['status'], $tr['faellig'] ? (string) $tr['faellig'] : null) ?>
                <?php if ($istOffen): ?>
                  <div class="winzig gedimmt"><?= $tageBis((string) $tr['faellig']) < 0 ? 'war fällig am' : 'fällig am' ?>
                    <?= Util::h(Util::datum((string) $tr['faellig'])) ?></div>
                <?php elseif ((string) $tr['status'] === 'bezahlt' && $tr['bezahlt']): ?>
                  <div class="winzig gedimmt">am <?= Util::h(Util::datum((string) $tr['bezahlt'])) ?></div>
                <?php endif; ?></td>
              <td class="aktionen"><a class="btn btn--klein" href="<?= Util::attr(App::url('/app/teepilot-rechnung.php?id=' . (int) $tr['id'])) ?>"
                  target="_blank" rel="noopener"><?= Icon::svg('download', 14) ?> PDF</a></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table></div>
    <?php endif; ?>
  </div>

  <div class="stapel">
    <?= karteAuf('Rechnungsanschrift') ?>
      <div class="karte__koerper">
        <address class="anschrift">
          <?php foreach ([
            $anschrift['firma'], $anschrift['name'], $anschrift['strasse'],
            trim($anschrift['plz'] . ' ' . $anschrift['ort']),
            $anschrift['land'] !== 'DE' ? (Betreiberrechnungen::LAENDER[$anschrift['land']] ?? $anschrift['land']) : '',
          ] as $zeile):
            if (trim((string) $zeile) === '') { continue; } ?>
            <span><?= Util::h((string) $zeile) ?></span>
          <?php endforeach; ?>
        </address>
        <dl class="angaben angaben--gestapelt mt-3">
          <?php if ($anschrift['ust_id'] !== ''): ?>
            <div class="angabe"><dt>USt-IdNr.</dt><dd class="mono"><?= Util::h($anschrift['ust_id']) ?></dd></div>
          <?php endif; ?>
          <div class="angabe"><dt>E-Mail für Rechnungen</dt>
            <dd><?= $anschrift['email'] !== '' ? Util::h($anschrift['email']) : '<span class="gedimmt">keine Adresse hinterlegt</span>' ?></dd></div>
        </dl>
        <?php if ($luecken !== []): ?>
          <div class="hinweis hinweis--warnung mt-3">
            <?= Icon::svg('alert', 16) ?>
            <div class="hinweis__text klein">Für eine Rechnung fehlt noch: <?= Util::h(implode(', ', $luecken)) ?>.</div>
          </div>
        <?php endif; ?>
        <p class="klein gedimmt mt-3"><?php if ($quelle === 'rechnung'): ?>So steht sie auf deiner letzten Rechnung
          (<?= Util::h($quelleNummer) ?>). <?php elseif ($quelle === 'vorschlag'): ?>Noch nicht hinterlegt – so würde sie
          nach deinen Angaben lauten. <?php endif; ?>Die Anschrift pflegt <?= Marke::NAME ?> für dich. Stimmt etwas nicht,
          <?php if ($kontakt !== ''): ?>schreib an <a href="mailto:<?= Util::attr($kontakt) ?>"><?= Util::h($kontakt) ?></a><?php
          else: ?>gib dem Support Bescheid<?php endif; ?> – neue Rechnungen lauten dann auf die neue Anschrift,
          ausgestellte bleiben, wie sie sind.</p>
      </div>
    </div>

    <?= karteAuf('Gut zu wissen') ?>
      <div class="karte__koerper stapel stapel--eng klein">
        <p>Eine neue Rechnung meldet dir die Glocke oben rechts. Offene Rechnungen stehen außerdem auf deinem Dashboard,
          bis die Zahlung verbucht ist.</p>
        <p class="gedimmt">Ausgestellte Rechnungen ändern sich nicht mehr. Wird eine korrigiert, bekommst du eine
          Stornorechnung und eine neue – beide stehen hier.</p>
      </div>
    </div>
  </div>
</div>

<?php require __DIR__ . '/partials/fuss.php'; ?>
