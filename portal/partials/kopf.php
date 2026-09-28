<?php
/**
 * Rahmen des Kundenportals.
 *
 * Bewusst eine andere Oberfläche als die Anwendung: Der Kunde verwaltet
 * nichts, er schaut nach. Deshalb keine Seitenleiste mit 22 Punkten,
 * sondern eine Leiste unten mit fünf Zielen – auf dem Telefon erreichbar,
 * ohne die Hand zu verlagern. Am Schreibtisch wandert dieselbe Leiste
 * nach oben.
 *
 * Erwartet vorher:
 *   $titel     Überschrift
 *   $kunde     Datensatz des angemeldeten Kunden
 *   $ansicht   aktueller Bereich
 *
 * Eigene Variablen beginnen mit $k und einem Großbuchstaben – die Datei
 * läuft im Gültigkeitsbereich der Seite, wie app/partials/kopf.php.
 */
if (!defined('GP_ROOT')) {
    exit;
}
require_once GP_ROOT . '/lib/vorlage.php';

$kBranding = Tenant::branding();
$ansicht   = $ansicht ?? 'start';
$kLogo     = (string) (Tenant::workspace()['logo'] ?? '');
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<title><?= Util::h($titel) ?> · <?= Util::h(Tenant::name()) ?></title>
<?php /* Schriften vom eigenen Server – siehe assets/css/schriften.css. */ ?>
<link rel="stylesheet" href="<?= Util::attr(App::asset('assets/css/schriften.css')) ?>">
<link rel="stylesheet" href="<?= Util::attr(App::asset('assets/css/portal.css')) ?>">
<style>
/* Nur die Marke kommt aus dem Workspace; alles andere steht im Stylesheet. */
.portal{
  --marke: <?= Util::attr((string) $kBranding['primaer']) ?>;
  --marke-dunkel: color-mix(in srgb, <?= Util::attr((string) $kBranding['primaer']) ?> 82%, #000);
  --akzent: <?= Util::attr((string) $kBranding['akzent']) ?>;
}
</style>
</head>
<body class="portal">

<header class="portal__kopf">
  <a class="portal__marke" href="<?= Util::attr(App::url('/portal/')) ?>">
    <?php if ($kLogo !== '' && is_file(GP_ROOT . '/' . ltrim($kLogo, '/'))): ?>
      <img src="<?= Util::attr(App::url($kLogo)) ?>" alt="<?= Util::attr(Tenant::name()) ?>" style="max-height:34px">
    <?php else: ?>
      <span class="portal__marke-text"><?= Util::h(Tenant::name()) ?></span>
    <?php endif; ?>
    <span class="portal__marke-zusatz">Dein Bereich</span>
  </a>
  <div class="fueller"></div>
  <?php
  /*
   * Buchen steht im Kopf, nicht in der Leiste unten.
   *
   * Die Leiste unten fuehrt zu Bereichen, in denen man nachsieht; Buchen
   * ist keiner davon, sondern das eine, was der Kunde hier tut. Als
   * sechstes Feld waere es zwischen „Fortschritt" und „Unterlagen"
   * untergegangen, und auf einem schmalen Telefon waeren alle sechs
   * Beschriftungen zu eng geworden. Oben steht es auf jeder Seite,
   * dauerhaft und in der Markenfarbe.
   */
  ?>
  <?php /* Die Beschriftung verschwindet auf schmalen Geraeten aus dem Bild,
           nicht aus der Seite: aria-label, damit das Zeichen auch dort nicht
           nur ein Pluszeichen ohne Bedeutung ist. */ ?>
  <a class="portal__buchen" aria-label="Termin buchen"
     href="<?= Util::attr(App::url('/portal/?ansicht=buchen')) ?>">
    <?= Icon::svg('plus', 16) ?><span>Termin buchen</span>
  </a>
  <a class="portal__ich" href="<?= Util::attr(App::url('/portal/?ansicht=profil')) ?>"
     aria-label="Mein Profil">
    <span class="avatar" style="background:<?= Util::attr(Util::avatarFarbe(Customers::name($kunde))) ?>">
      <?= Util::h(Util::initialen(Customers::name($kunde))) ?></span>
  </a>
</header>

<nav class="portal__navi" aria-label="Bereiche">
  <?php foreach ([
    ['start',     'Start',    'home'],
    ['termine',   'Termine',  'calendar'],
    ['training',  'Training', 'training'],
    ['fortschritt', 'Fortschritt', 'trend-up'],
    ['unterlagen', 'Unterlagen', 'folder'],
  ] as [$kZiel, $kName, $kIcon]): ?>
    <a class="portal__navi-teil<?= $ansicht === $kZiel ? ' ist-aktiv' : '' ?>"
       href="<?= Util::attr(App::url('/portal/?ansicht=' . $kZiel)) ?>">
      <?= Icon::svg($kIcon, 19) ?>
      <span><?= Util::h($kName) ?></span>
    </a>
  <?php endforeach; ?>
</nav>

<main class="portal__inhalt">
  <?php foreach (App::meldungen() as $kM): ?>
    <div class="hinweis hinweis--<?= $kM['typ'] === 'fehler' ? 'gefahr' : ($kM['typ'] === 'info' ? 'still' : 'erfolg') ?> mb-4">
      <?= Icon::svg($kM['typ'] === 'fehler' ? 'alert' : 'check', 17) ?>
      <div class="hinweis__text"><?= Util::h($kM['text']) ?></div>
    </div>
  <?php endforeach; ?>
  <h1 class="portal__titel"><?= Util::h($titel) ?></h1>
