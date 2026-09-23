<?php
/**
 * Ein Baustein auf der Leinwand des Baukastens.
 *
 * Steht in einer eigenen Datei, weil ihn zwei Stellen ausgeben: die Seite
 * app/seite.php beim ersten Laden und der Endpunkt app/bauen.php, wenn ein
 * Baustein hinzukommt oder sich ändert. Zwei Fassungen liefen
 * auseinander – und dann sähe ein frisch eingefügter Baustein anders aus
 * als derselbe nach dem Neuladen.
 *
 * Erwartet: $bauBlock (der Baustein), $bauGewaehlt (Kennung des
 * gewählten). `Renderer::bearbeitbar()` schaltet der Aufrufer.
 *
 * Werkzeuge, Namensschild und Rahmen stehen nicht hier, sondern werden
 * vom Skript über die Leinwand gelegt: Die Leinwand ist verkleinert, und
 * Knöpfe darin wären es mit.
 */
if (!defined('GP_ROOT')) {
    exit;
}
$bbId   = (string) ($bauBlock['id'] ?? '');
$bbTyp  = (string) ($bauBlock['typ'] ?? '');
$bbHtml = Renderer::block($bauBlock);
?>
<div class="bau-block<?= $bbId === (string) ($bauGewaehlt ?? '') ? ' ist-gewaehlt' : '' ?>"
     id="block-<?= Util::attr($bbId) ?>" data-block-id="<?= Util::attr($bbId) ?>"
     data-typ="<?= Util::attr($bbTyp) ?>" data-name="<?= Util::attr(Bloecke::name($bbTyp)) ?>">
  <?php /*
   * Ein leerer Baustein bekommt einen Platzhalter.
   *
   * Galerie, Karten, Logoleiste und FAQ geben ohne Inhalt eine leere
   * Zeichenkette zurück. Im Baukasten wurde daraus ein vier Pixel hoher
   * Streifen: unsichtbar, nicht anklickbar, nicht auswählbar – und damit
   * auch nicht zu entfernen.
   */
  if (trim($bbHtml) === ''): ?>
    <div class="bau-block__leer">
      <?= Icon::svg(Bloecke::icon($bbTyp), 22) ?>
      <p class="halbfett"><?= Util::h(Bloecke::name($bbTyp)) ?> – noch nichts eingetragen</p>
      <p class="klein gedimmt">Anklicken und rechts ausfüllen – dann erscheint der Baustein hier und auf der Website.</p>
    </div>
  <?php else: ?>
    <?= $bbHtml ?>
  <?php endif; ?>
</div>
