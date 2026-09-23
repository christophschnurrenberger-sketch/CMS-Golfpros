<?php
/**
 * Die rechte Spalte des Baukastens: die Felder des gewählten Bausteins –
 * oder, wenn keiner gewählt ist, die Angaben zur Seite.
 *
 * Wie der Baustein auf der Leinwand wird auch diese Spalte an zwei
 * Stellen ausgegeben: beim Laden der Seite und vom Endpunkt, wenn man an
 * Ort und Stelle einen anderen Baustein wählt.
 *
 * Ohne JavaScript sind es gewöhnliche Formulare mit Speicherknopf. Mit
 * JavaScript speichert das Formular des Bausteins selbst, sobald sich
 * etwas ändert, und die Leinwand zeigt es gleich. Die Knöpfe mit
 * `data-bau-aktion` übernimmt dann das Skript; ohne es schicken sie ihr
 * Formular ab.
 *
 * Erwartet: $bauSeite, $bauBloecke, $bauBlock (oder null), $bauSchreiben.
 */
if (!defined('GP_ROOT')) {
    exit;
}
$bpId     = (int) $bauSeite['id'];
$bpZiel   = App::url('/app/seite.php?id=' . $bpId);
$bpRo     = !$bauSchreiben ? ' disabled' : '';

if ($bauBlock !== null):
    $bpTyp    = (string) $bauBlock['typ'];
    $bpDef    = Bloecke::typ($bpTyp) ?? [];
    $bpDaten  = (array) ($bauBlock['daten'] ?? []);
    $bpBlock  = (string) $bauBlock['id'];
    $bpStelle = Baukasten::stelle($bauBloecke, $bpBlock) ?? 0;
    $bpAnzahl = count($bauBloecke);
    /* Felder, die sich auf der Leinwand anklicken lassen – für den Hinweis oben. */
    $bpDirekt = array_filter((array) ($bpDef['felder'] ?? []),
        static fn ($d) => in_array($d[1] ?? 'text', ['text', 'mehrzeilig', 'bild', 'liste'], true));
?>
  <div class="bau-panel" data-panel data-panel-block="<?= Util::attr($bpBlock) ?>">
    <div class="bau-panel__kopf">
      <span class="bau-panel__symbol"><?= Icon::svg(Bloecke::icon($bpTyp), 16) ?></span>
      <div class="bau-panel__titel">
        <h2><?= Util::h(Bloecke::name($bpTyp)) ?></h2>
        <span class="klein gedimmt" data-panel-stelle>Baustein <?= $bpStelle + 1 ?> von <?= $bpAnzahl ?></span>
      </div>
      <a href="<?= Util::attr($bpZiel) ?>" class="rundknopf rundknopf--klein" data-bau-aktion="abwaehlen"
         title="Auswahl aufheben (Esc)" aria-label="Auswahl aufheben"><?= Icon::svg('x', 15) ?></a>
    </div>

    <form method="post" action="<?= Util::attr($bpZiel) ?>" class="bau-panel__form" data-block-form>
      <?= Auth::csrfFeld() ?>
      <input type="hidden" name="aktion" value="block_speichern">
      <input type="hidden" name="block_id" value="<?= Util::attr($bpBlock) ?>">

      <div class="bau-panel__felder">
        <?php if ($bpDirekt !== [] && $bauSchreiben): ?>
          <div class="bau-tipp">
            <?= Icon::svg('edit', 14) ?>
            <span>Texte und Bilder kannst du auch direkt auf der Seite anklicken und ändern.</span>
          </div>
        <?php endif; ?>

        <?php foreach ((array) ($bpDef['felder'] ?? []) as $feld => $def):
          $label  = (string) $def[0];
          $art    = (string) ($def[1] ?? 'text');
          $zusatz = $def[2] ?? null;
          $wert   = $bpDaten[$feld] ?? '';
          $fid    = 'f_' . $feld; ?>

          <?php if ($art === 'liste'):
            $eintraege = is_array($wert) ? array_values($wert) : []; ?>
            <div class="feld bau-listenfeld" data-liste="<?= Util::attr($feld) ?>">
              <div class="bau-listenfeld__kopf">
                <span class="feld__label"><?= Util::h($label) ?></span>
                <span class="bau-listenfeld__zahl" data-listen-zahl><?= count($eintraege) ?></span>
              </div>
              <div class="bau-listenfeld__eintraege" data-eintraege>
                <?php
                /* Eine leere Karte am Ende: ohne Skript der Weg, einen
                   Eintrag anzuhängen; mit Skript die Vorlage für „+ Eintrag". */
                $eintraege[] = null;
                foreach ($eintraege as $n => $eintrag):
                  $istVorlage = $eintrag === null; ?>
                  <div class="bau-eintragkarte"<?= $istVorlage ? ' data-vorlage' : '' ?> data-eintragkarte>
                    <div class="bau-eintragkarte__kopf">
                      <span class="bau-eintragkarte__nr" data-nr-anzeige><?= $istVorlage ? 'Neuer Eintrag' : 'Eintrag ' . ($n + 1) ?></span>
                      <button type="button" class="bau-eintragkarte__weg" data-eintrag-entfernen hidden
                              aria-label="Eintrag entfernen" title="Eintrag entfernen"><?= Icon::svg('trash', 13) ?></button>
                    </div>
                    <?php foreach ((array) $zusatz as $uf => $udef):
                      $ulabel = (string) $udef[0];
                      $uart   = (string) ($udef[1] ?? 'text');
                      $uwert  = (string) ($eintrag[$uf] ?? '');
                      $uname  = 'l_' . $feld . '_' . $uf . '[]'; ?>
                      <div class="feld feld--eng">
                        <label class="feld__label feld__label--klein"><?= Util::h($ulabel) ?></label>
                        <?php if ($uart === 'mehrzeilig'): ?>
                          <textarea class="eingabe" name="<?= Util::attr($uname) ?>" rows="2" data-waechst<?= $bpRo ?>><?= Util::h($uwert) ?></textarea>
                        <?php elseif ($uart === 'auswahl' && ($udef[2] ?? null) === 'icons'): ?>
                          <select name="<?= Util::attr($uname) ?>"<?= $bpRo ?>>
                            <option value="">—</option>
                            <?php foreach (['target', 'video', 'training', 'award', 'check', 'star',
                                            'clock', 'customers', 'euro', 'flag', 'trophy', 'zap'] as $ikon): ?>
                              <option value="<?= $ikon ?>"<?= $uwert === $ikon ? ' selected' : '' ?>><?= $ikon ?></option>
                            <?php endforeach; ?>
                          </select>
                        <?php elseif ($uart === 'schalter'): ?>
                          <select name="<?= Util::attr($uname) ?>"<?= $bpRo ?>>
                            <option value=""<?= $uwert === '' ? ' selected' : '' ?>>Nein</option>
                            <option value="1"<?= $uwert !== '' ? ' selected' : '' ?>>Ja</option>
                          </select>
                        <?php elseif ($uart === 'bild'): ?>
                          <?= bildfeld('', $uname, $uwert, ['eng' => true]) ?>
                        <?php else: ?>
                          <input class="eingabe" name="<?= Util::attr($uname) ?>" value="<?= Util::attr($uwert) ?>"<?= $bpRo ?>>
                        <?php endif; ?>
                      </div>
                    <?php endforeach; ?>
                  </div>
                <?php endforeach; ?>
              </div>
              <?php if ($bauSchreiben): ?>
                <button type="button" class="btn btn--klein btn--voll bau-listenfeld__hinzu" data-eintrag-hinzu hidden>
                  <?= Icon::svg('plus', 14) ?> Eintrag hinzufügen</button>
              <?php endif; ?>
              <div class="feld__hinweis" data-nur-ohne-js>Die letzte, leere Karte fügt einen weiteren Eintrag hinzu.
                Eintrag leeren entfernt ihn.</div>
            </div>

          <?php elseif ($art === 'mehrzeilig'): ?>
            <div class="feld">
              <label class="feld__label" for="<?= $fid ?>"><?= Util::h($label) ?></label>
              <textarea class="eingabe" id="<?= $fid ?>" name="<?= $fid ?>" rows="4" data-waechst<?= $bpRo ?>><?= Util::h((string) $wert) ?></textarea>
            </div>

          <?php elseif ($art === 'auswahl'): ?>
            <div class="feld">
              <label class="feld__label" for="<?= $fid ?>"><?= Util::h($label) ?></label>
              <?php if (count((array) $zusatz) <= 3): ?>
                <?php /* Zwei oder drei Möglichkeiten sieht man besser nebeneinander als hinter einem Klappmenü. */ ?>
                <div class="segment segment--voll" role="radiogroup" aria-label="<?= Util::attr($label) ?>">
                  <?php foreach ((array) $zusatz as $k => $v): ?>
                    <label class="segment__teil<?= (string) $wert === (string) $k ? ' ist-aktiv' : '' ?>">
                      <input type="radio" class="nur-lesbar" name="<?= $fid ?>" value="<?= Util::attr((string) $k) ?>"
                             <?= (string) $wert === (string) $k ? ' checked' : '' ?><?= $bpRo ?>>
                      <?= Util::h((string) $v) ?></label>
                  <?php endforeach; ?>
                </div>
              <?php else: ?>
                <select id="<?= $fid ?>" name="<?= $fid ?>"<?= $bpRo ?>>
                  <?php foreach ((array) $zusatz as $k => $v): ?>
                    <option value="<?= Util::attr((string) $k) ?>"<?= (string) $wert === (string) $k ? ' selected' : '' ?>>
                      <?= Util::h((string) $v) ?></option>
                  <?php endforeach; ?>
                </select>
              <?php endif; ?>
            </div>

          <?php elseif ($art === 'schalter'): ?>
            <label class="haken mb-4">
              <input type="checkbox" name="<?= $fid ?>" value="1"<?= !empty($wert) ? ' checked' : '' ?><?= $bpRo ?>>
              <span class="haken__text"><?= Util::h($label) ?></span>
            </label>

          <?php elseif ($art === 'zahl'): ?>
            <div class="feld">
              <label class="feld__label" for="<?= $fid ?>"><?= Util::h($label) ?></label>
              <input class="eingabe" id="<?= $fid ?>" type="number" name="<?= $fid ?>" value="<?= Util::attr((string) $wert) ?>"<?= $bpRo ?>>
            </div>

          <?php elseif ($art === 'bild'): ?>
            <?= bildfeld($label, $fid, (string) $wert, ['id' => $fid,
                  'hinweis' => 'Aus der Mediathek wählen, hochladen oder eine Adresse eintragen.']) ?>

          <?php else: ?>
            <div class="feld">
              <label class="feld__label" for="<?= $fid ?>"><?= Util::h($label) ?></label>
              <input class="eingabe" id="<?= $fid ?>" name="<?= $fid ?>" value="<?= Util::attr((string) $wert) ?>"<?= $bpRo ?>
                     <?= $art === 'url' ? ' placeholder="#buchung, /preise oder https://…"' : '' ?>>
            </div>
          <?php endif; ?>
        <?php endforeach; ?>
      </div>

      <?php if ($bauSchreiben): ?>
        <div class="bau-panel__fuss">
          <button class="btn btn--primaer btn--voll" type="submit" data-nur-ohne-js>Baustein speichern</button>
          <div class="bau-panel__aktionen">
            <button class="btn btn--klein" type="submit" form="bau-hoch" data-bau-aktion="hoch"
                    title="Nach oben (Alt + ↑)"<?= $bpStelle === 0 ? ' disabled' : '' ?>><?= Icon::svg('arrow-up', 14) ?><span class="nur-lesbar">Nach oben</span></button>
            <button class="btn btn--klein" type="submit" form="bau-runter" data-bau-aktion="runter"
                    title="Nach unten (Alt + ↓)"<?= $bpStelle >= $bpAnzahl - 1 ? ' disabled' : '' ?>><?= Icon::svg('arrow-down', 14) ?><span class="nur-lesbar">Nach unten</span></button>
            <button class="btn btn--klein" type="submit" form="bau-kopieren" data-bau-aktion="kopie"
                    title="Kopieren (Strg + D)"><?= Icon::svg('copy', 14) ?> Kopieren</button>
            <div class="fueller"></div>
            <button class="btn btn--klein btn--gefahr-text" type="submit" form="bau-entfernen" data-bau-aktion="weg"
                    title="Entfernen (Entf)"><?= Icon::svg('trash', 14) ?> Entfernen</button>
          </div>
        </div>
      <?php endif; ?>
    </form>

    <?php if ($bauSchreiben):
      /* Formulare lassen sich nicht schachteln – die Knöpfe oben erreichen diese über `form=`. */
      foreach ([['bau-hoch', 'block_schieben', 'hoch'], ['bau-runter', 'block_schieben', 'runter'],
                ['bau-kopieren', 'block_kopie', ''], ['bau-entfernen', 'block_weg', '']] as [$fId, $fAktion, $fRichtung]): ?>
        <form method="post" action="<?= Util::attr($bpZiel) ?>" id="<?= $fId ?>" hidden
              <?= $fAktion === 'block_weg' ? 'data-bestaetigen="Diesen Baustein entfernen?"' : '' ?>>
          <?= Auth::csrfFeld() ?>
          <input type="hidden" name="aktion" value="<?= $fAktion ?>">
          <input type="hidden" name="block_id" value="<?= Util::attr($bpBlock) ?>">
          <?php if ($fRichtung !== ''): ?><input type="hidden" name="richtung" value="<?= $fRichtung ?>"><?php endif; ?>
        </form>
      <?php endforeach;
    endif; ?>
  </div>

<?php else:
  $bpSeo = Pages::seo($bauSeite); ?>
  <div class="bau-panel" data-panel data-panel-seite>
    <div class="bau-panel__kopf">
      <span class="bau-panel__symbol"><?= Icon::svg('website', 16) ?></span>
      <div class="bau-panel__titel">
        <h2>Seite</h2>
        <span class="klein gedimmt">Klick auf einen Baustein, um ihn zu bearbeiten.</span>
      </div>
    </div>
    <form method="post" action="<?= Util::attr($bpZiel) ?>" class="bau-panel__form">
      <?= Auth::csrfFeld() ?>
      <input type="hidden" name="aktion" value="seite_speichern">
      <div class="bau-panel__felder">
        <div class="feld"><label class="feld__label" for="s-titel">Titel</label>
          <input class="eingabe" id="s-titel" name="titel" value="<?= Util::attr((string) $bauSeite['titel']) ?>"<?= $bpRo ?>></div>
        <div class="feld"><label class="feld__label" for="s-slug">Adresse</label>
          <div class="eingabe-gruppe">
            <span class="eingabe-gruppe__anhang eingabe-gruppe__anhang--vorn">/</span>
            <input class="eingabe" id="s-slug" name="slug" value="<?= Util::attr((string) $bauSeite['slug']) ?>"<?= $bpRo ?>>
          </div></div>
        <div class="feld"><label class="feld__label" for="s-status">Status</label>
          <select id="s-status" name="status"<?= $bpRo ?>>
            <option value="entwurf"<?= (string) $bauSeite['status'] === 'entwurf' ? ' selected' : '' ?>>Entwurf – nicht öffentlich</option>
            <option value="veroeffentlicht"<?= (string) $bauSeite['status'] === 'veroeffentlicht' ? ' selected' : '' ?>>Veröffentlicht</option>
          </select></div>
        <div class="feld"><label class="feld__label" for="s-art">Art</label>
          <select id="s-art" name="art"<?= $bpRo ?>>
            <option value="seite"<?= (string) $bauSeite['art'] === 'seite' ? ' selected' : '' ?>>Normale Seite</option>
            <option value="landingpage"<?= (string) $bauSeite['art'] === 'landingpage' ? ' selected' : '' ?>>Landingpage</option>
          </select></div>
        <?php if ((int) $bauSeite['startseite'] !== 1):
          $bpMoeglich = Pages::elternMoeglich($bpId); ?>
          <div class="feld"><label class="feld__label" for="s-eltern">Untergeordnet</label>
            <select id="s-eltern" name="parent_id"<?= $bpRo ?>>
              <option value="0">— oberste Ebene —</option>
              <?php foreach ($bpMoeglich as $m): ?>
                <option value="<?= (int) $m['id'] ?>"<?= (int) $bauSeite['parent_id'] === (int) $m['id'] ? ' selected' : '' ?>>
                  <?= str_repeat('— ', (int) $m['tiefe']) ?><?= Util::h((string) $m['titel']) ?></option>
              <?php endforeach; ?>
            </select>
            <div class="feld__hinweis">
              <?php if ($bpMoeglich === []): ?>
                Diese Seite hat selbst schon Unterseiten über <?= Pages::MAX_TIEFE ?> Ebenen – tiefer geht es nicht.
              <?php else: ?>
                Im Menü steht sie dann im Klappmenü dieser Seite. Bis zu <?= Pages::MAX_TIEFE ?> Ebenen; die Adresse bleibt
                <code>/<?= Util::h((string) $bauSeite['slug']) ?></code>.
              <?php endif; ?></div></div>
        <?php endif; ?>
        <label class="haken mb-4">
          <input type="checkbox" name="im_menue" value="1"<?= (int) $bauSeite['im_menue'] === 1 ? ' checked' : '' ?><?= $bpRo ?>>
          <span class="haken__text">Im Menü zeigen
            <span class="haken__hinweis">Aus heißt: erreichbar, aber nicht in der Navigation.
              Unterseiten einer ausgeblendeten Seite rücken im Menü eine Ebene hoch.</span></span>
        </label>

        <hr>
        <div class="versal gedimmt-2 mb-3">Für Suchmaschinen</div>
        <div class="feld"><label class="feld__label" for="s-seo-titel">Seitentitel</label>
          <input class="eingabe" id="s-seo-titel" name="seo_titel" maxlength="70"
                 value="<?= Util::attr((string) $bpSeo['titel']) ?>" data-zaehler="zaehler-titel"<?= $bpRo ?>>
          <div class="feld__hinweis" id="zaehler-titel"></div></div>
        <div class="feld"><label class="feld__label" for="s-seo-text">Beschreibung</label>
          <textarea class="eingabe" id="s-seo-text" name="seo_beschreibung" rows="3" maxlength="170"
                    data-zaehler="zaehler-text" data-waechst<?= $bpRo ?>><?= Util::h((string) $bpSeo['beschreibung']) ?></textarea>
          <div class="feld__hinweis" id="zaehler-text"></div></div>
        <label class="haken mb-4">
          <input type="checkbox" name="seo_index" value="1"<?= !empty($bpSeo['index']) ? ' checked' : '' ?><?= $bpRo ?>>
          <span class="haken__text">Von Google indexieren lassen</span>
        </label>

        <div class="klein gedimmt mt-5">
          <div class="reihe"><span>Aufrufe</span><div class="fueller"></div>
            <span class="halbfett"><?= Util::zahl((int) $bauSeite['aufrufe']) ?></span></div>
          <?php $bpConv = Pages::conversion($bauSeite); ?>
          <div class="reihe mt-2"><span>Anfragen</span><div class="fueller"></div>
            <span class="halbfett"><?= (int) $bauSeite['conversions'] ?><?= $bpConv !== null
              ? ' (' . Util::prozent($bpConv, 1) . ')' : '' ?></span></div>
        </div>
      </div>
      <?php if ($bauSchreiben): ?>
        <div class="bau-panel__fuss">
          <button class="btn btn--primaer btn--voll" type="submit">Seite speichern</button>
        </div>
      <?php endif; ?>
    </form>

    <?php if ($bauSchreiben): ?>
      <div class="bau-panel__extra">
        <?php if ((int) $bauSeite['startseite'] === 0): ?>
          <form method="post" action="<?= Util::attr($bpZiel) ?>" class="mb-2">
            <?= Auth::csrfFeld() ?>
            <input type="hidden" name="aktion" value="startseite">
            <button class="btn btn--voll btn--klein" type="submit"><?= Icon::svg('home', 14) ?> Zur Startseite machen</button>
          </form>
          <form method="post" action="<?= Util::attr($bpZiel) ?>" data-bestaetigen="Diese Seite löschen?">
            <?= Auth::csrfFeld() ?>
            <input type="hidden" name="aktion" value="loeschen">
            <button class="btn btn--voll btn--klein btn--gefahr-text" type="submit"><?= Icon::svg('trash', 14) ?> Seite löschen</button>
          </form>
        <?php else: ?>
          <div class="hinweis hinweis--still">
            <?= Icon::svg('home', 15) ?>
            <div class="hinweis__text klein">Das ist die Startseite.</div>
          </div>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </div>
<?php endif; ?>
