<?php
/**
 * Der Assistent.
 *
 * Fragen zu Zahlen beantwortet eine Datenbankabfrage, nicht ein Modell.
 * Das ist schneller, kostet nichts und ist überprüfbar – jede Antwort
 * führt auf die Liste, aus der sie stammt.
 */
require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/partials/helfer.php';

Auth::fordern('modul.ai');
Auth::fordern('ai.use');

$konversation = App::get('k', 'haupt');
$frage = App::get('frage', App::post('frage'));

if (App::istPost()) {
    Auth::csrfFordern();
    if (App::aktion() === 'leeren') {
        Tenant::deleteWhere('ai_messages', 'konversation = :k AND user_id = :u',
            ['k' => $konversation, 'u' => Auth::id()]);
        App::weiter('/app/ki.php');
    }
}

if ($frage !== '') {
    Tenant::insert('ai_messages', [
        'user_id' => Auth::id(), 'konversation' => $konversation,
        'rolle' => 'user', 'text' => $frage,
    ]);
    $antwort = KI::frage($frage);
    Tenant::insert('ai_messages', [
        'user_id' => Auth::id(), 'konversation' => $konversation,
        'rolle' => 'assistent', 'text' => $antwort['text'],
        'daten' => Util::json(['quelle' => $antwort['quelle'], 'link' => $antwort['link']]),
    ]);
    App::weiter('/app/ki.php');
}

$verlauf = Tenant::all('ai_messages', 'konversation = :k AND user_id = :u',
    ['k' => $konversation, 'u' => Auth::id()], 'id', 40);
$empfehlungen = Empfehlungen::fuerDashboard(4);
$kontakte = Empfehlungen::naechsteKontakte(5);

$beispiele = [
    'Was war mein Umsatz im August?',
    'Welche Kunden habe ich seit 90 Tagen nicht gesehen?',
    'Welche Leistungen verkaufen sich am besten?',
    'Wie voll ist meine nächste Woche?',
    'Welche Rechnungen sind überfällig?',
    'Wen sollte ich heute anrufen?',
    'Was steht heute an?',
    'Wie viele neue Kunden diesen Monat?',
];

$titel = 'KI-Assistent';
$unter = KI::verfuegbar()
    ? 'Fragen zu deinen Zahlen beantwortet die Datenbank, alles andere das Sprachmodell.'
    : 'Kein KI-Zugang hinterlegt – Fragen zu deinen Zahlen beantwortet die Datenbank trotzdem.';
$aktionen = $verlauf !== []
    ? '<form method="post" data-bestaetigen="Verlauf löschen?">' . Auth::csrfFeld()
      . '<input type="hidden" name="aktion" value="leeren">'
      . '<button class="btn" type="submit">' . Icon::svg('trash', 15) . ' Verlauf leeren</button></form>'
    : '';
require __DIR__ . '/partials/kopf.php';
?>

<div class="raster raster--haupt-neben">
  <div class="stapel">
    <div class="karte">
      <div class="karte__koerper" style="min-height:380px">
        <?php if ($verlauf === []): ?>
          <div class="leerzustand">
            <div class="leerzustand__symbol"><?= Icon::svg('ai', 26) ?></div>
            <h3>Frag deine Zahlen</h3>
            <p>Der Assistent liest deine Daten und antwortet mit dem, was wirklich drinsteht.
              Bei Umsatz, Auslastung, Kunden und Rechnungen rechnet die Datenbank –
              erfundene Zahlen kann es dabei nicht geben.</p>
          </div>
        <?php else: ?>
          <div class="ki-verlauf">
            <?php foreach ($verlauf as $m):
              $daten = Util::ausJson((string) $m['daten']); ?>
              <?php if ((string) $m['rolle'] === 'user'): ?>
                <div class="ki-blase ki-blase--ich"><?= Util::h((string) $m['text']) ?></div>
              <?php else: ?>
                <div>
                  <div class="ki-blase ki-blase--ki" style="white-space:pre-wrap"><?= Util::h((string) $m['text']) ?></div>
                  <div class="reihe reihe--eng mt-2" style="font-size:11.5px;color:var(--text-4)">
                    <?php if (($daten['quelle'] ?? '') === 'daten'): ?>
                      <?= Icon::svg('shield', 12) ?> aus deiner Datenbank berechnet
                    <?php elseif (($daten['quelle'] ?? '') === 'modell'): ?>
                      <?= Icon::svg('ai', 12) ?> vom Sprachmodell formuliert
                    <?php else: ?>
                      <?= Icon::svg('info', 12) ?> eingebaute Antwort
                    <?php endif; ?>
                    <?php if (!empty($daten['link'])): ?>
                      <a href="<?= Util::attr(App::url((string) $daten['link'])) ?>" style="margin-left:8px">
                        Liste ansehen</a>
                    <?php endif; ?>
                  </div>
                </div>
              <?php endif; ?>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
      <div class="karte__fuss" style="padding:var(--r4) var(--r5)">
        <form method="get" class="reihe reihe--eng" style="width:100%">
          <input class="eingabe" name="frage" placeholder="Frag etwas über dein Geschäft…"
                 autofocus autocomplete="off" aria-label="Frage">
          <button class="btn btn--primaer umbruch-nein" type="submit">
            <?= Icon::svg('send', 15) ?> Fragen</button>
        </form>
      </div>
    </div>

    <div class="karte">
      <div class="karte__kopf"><h3>Beispiele</h3></div>
      <div class="karte__koerper karte__koerper--eng">
        <div class="reihe reihe--eng reihe--umbruch">
          <?php foreach ($beispiele as $b): ?>
            <a class="pille pille--offen pille--gross"
               href="<?= Util::attr(App::url('/app/ki.php?frage=' . rawurlencode($b))) ?>">
              <?= Util::h($b) ?></a>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

    <div class="karte">
      <div class="karte__kopf"><h3>Wen heute anrufen?</h3>
        <div class="fueller"></div>
        <span class="klein gedimmt">nach Dringlichkeit</span></div>
      <div class="karte__koerper karte__koerper--eng">
        <?php if ($kontakte === []): ?>
          <p class="klein gedimmt mitte" style="padding:var(--r3) 0">
            Alle aktiven Kunden haben einen Termin oder waren kürzlich da.</p>
        <?php else: ?>
          <div class="stapel stapel--eng">
            <?php foreach ($kontakte as $v): ?>
              <div class="empfehlung">
                <div class="empfehlung__symbol empfehlung__symbol--<?=
                    $v['dringlichkeit'] === 'hoch' ? 'gefahr' : ($v['dringlichkeit'] === 'mittel' ? 'warnung' : '') ?>">
                  <?= Icon::svg('phone', 16) ?>
                </div>
                <div class="empfehlung__text">
                  <div class="empfehlung__titel"><?= Util::h(Customers::name($v['kunde'])) ?></div>
                  <div class="empfehlung__grund"><?= Util::h($v['grund']) ?></div>
                </div>
                <div class="empfehlung__aktion">
                  <?php if ((string) $v['kunde']['telefon'] !== ''): ?>
                    <a class="btn btn--klein" href="tel:<?= Util::attr((string) $v['kunde']['telefon']) ?>">
                      <?= Icon::svg('phone', 13) ?></a>
                  <?php endif; ?>
                  <a class="btn btn--klein" href="<?= Util::attr(App::url('/app/kunde.php?id=' . (int) $v['kunde']['id'])) ?>">
                    Akte</a>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="stapel">
    <div class="karte">
      <div class="karte__kopf"><h3>Empfehlungen</h3></div>
      <div class="karte__koerper karte__koerper--eng">
        <div class="stapel stapel--eng">
          <?php foreach ($empfehlungen as $e): ?>
            <a class="kachel-link" href="<?= Util::attr(App::url($e['url'])) ?>">
              <span class="kachel-link__symbol" style="width:32px;height:32px"><?= Icon::svg($e['icon'], 15) ?></span>
              <div style="flex:1;min-width:0">
                <div class="kachel-link__titel"><?= Util::h($e['titel']) ?></div>
                <div class="kachel-link__text"><?= Util::h(Util::kuerzen($e['grund'], 90)) ?></div>
              </div>
            </a>
          <?php endforeach; ?>
          <?php if ($empfehlungen === []): ?>
            <p class="klein gedimmt mitte" style="padding:var(--r3) 0">Nichts liegt an.</p>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <div class="karte">
      <div class="karte__kopf"><h3>Was der Assistent kann</h3></div>
      <div class="karte__koerper karte__koerper--eng">
        <div class="stapel stapel--eng klein">
          <?php foreach ([
            ['Zahlen beantworten', 'Umsatz, Auslastung, Rechnungen, Kunden – aus der Datenbank', 'shield'],
            ['Texte entwerfen', 'Website, Landingpage, Newsletter, Social Media', 'content'],
            ['Trainingspläne vorschlagen', 'aus deiner Übungsbibliothek, nach Schwäche gewichtet', 'training'],
            ['Kunden einschätzen', 'Gesundheitswert erklären, nächsten Schritt vorschlagen', 'customers'],
          ] as [$name, $text, $icon]): ?>
            <div class="reihe reihe--eng" style="align-items:flex-start;padding:5px 0">
              <span style="color:var(--marke);margin-top:1px"><?= Icon::svg($icon, 15) ?></span>
              <div>
                <div class="halbfett"><?= Util::h($name) ?></div>
                <div class="gedimmt"><?= Util::h($text) ?></div>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

    <div class="hinweis hinweis--still">
      <?= Icon::svg('shield', 17) ?>
      <div class="hinweis__text">
        <span class="hinweis__titel">Wo die Grenze liegt</span>
        Der Assistent führt nichts aus. Preise ändern, Rechnungen schreiben, Newsletter
        versenden, Daten löschen – das macht ein Mensch mit einem Klick.
        Er sieht außerdem nur Daten dieses Workspace.
        <?php if (!KI::verfuegbar()): ?>
          <br><br>Es ist kein KI-Zugang hinterlegt. Fragen zu Zahlen funktionieren trotzdem,
          weil sie aus der Datenbank kommen. Für frei formulierte Antworten trägst du in der
          <code>config.php</code> einen API-Schlüssel ein.
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<?php require __DIR__ . '/partials/fuss.php'; ?>
