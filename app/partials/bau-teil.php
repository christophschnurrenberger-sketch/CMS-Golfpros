<?php
/**
 * Eine Zeile im Aufbau links – ein Baustein der Seite.
 *
 * Ohne JavaScript ist sie ein Link, der den Baustein auswählt. Mit
 * JavaScript wählt ein Klick an Ort und Stelle aus, und am Griff lässt
 * sich die Zeile ziehen. Unter dem Namen steht die Überschrift des
 * Bausteins: Drei „Titelbereich" untereinander sagen nichts, drei
 * Überschriften schon.
 *
 * Erwartet: $bauBlock, $bauSeiteId, $bauGewaehlt.
 */
if (!defined('GP_ROOT')) {
    exit;
}
$btId   = (string) ($bauBlock['id'] ?? '');
$btTyp  = (string) ($bauBlock['typ'] ?? '');
$btKurz = Baukasten::kurz($bauBlock);
?>
<a class="bau-teil<?= $btId === (string) ($bauGewaehlt ?? '') ? ' ist-gewaehlt' : '' ?>"
   href="<?= Util::attr(App::url('/app/seite.php?id=' . (int) $bauSeiteId . '&block=' . rawurlencode($btId))) ?>"
   data-teil="<?= Util::attr($btId) ?>" data-name="<?= Util::attr(Bloecke::name($btTyp)) ?>">
  <span class="bau-teil__griff" data-griff title="Ziehen, um den Baustein zu verschieben"><?= Icon::svg('grip', 14) ?></span>
  <span class="bau-teil__symbol"><?= Icon::svg(Bloecke::icon($btTyp), 15) ?></span>
  <span class="bau-teil__text">
    <span class="bau-teil__name"><?= Util::h(Bloecke::name($btTyp)) ?></span>
    <?php if ($btKurz !== ''): ?><span class="bau-teil__kurz"><?= Util::h($btKurz) ?></span><?php endif; ?>
  </span>
</a>
