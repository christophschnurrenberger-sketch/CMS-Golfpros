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
 */
if (!defined('GP_ROOT')) {
    exit;
}
require_once GP_ROOT . '/lib/vorlage.php';

$branding = Tenant::branding();
$ansicht  = $ansicht ?? 'start';
$logo     = (string) (Tenant::workspace()['logo'] ?? '');
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
  --marke: <?= Util::attr((string) $branding['primaer']) ?>;
  --marke-dunkel: color-mix(in srgb, <?= Util::attr((string) $branding['primaer']) ?> 82%, #000);
  --akzent: <?= Util::attr((string) $branding['akzent']) ?>;
}
</style>
</head>
<body class="portal">

<header class="portal__kopf">
  <a class="portal__marke" href="<?= Util::attr(App::url('/portal/')) ?>">
    <?php if ($logo !== '' && is_file(GP_ROOT . '/' . ltrim($logo, '/'))): ?>
      <img src="<?= Util::attr(App::url($logo)) ?>" alt="<?= Util::attr(Tenant::name()) ?>" style="max-height:34px">
    <?php else: ?>
      <span class="portal__marke-text"><?= Util::h(Tenant::name()) ?></span>
    <?php endif; ?>
    <span class="portal__marke-zusatz">Dein Bereich</span>
  </a>
  <div class="fueller"></div>
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
  ] as [$key, $name, $icon]): ?>
    <a class="portal__navi-teil<?= $ansicht === $key ? ' ist-aktiv' : '' ?>"
       href="<?= Util::attr(App::url('/portal/?ansicht=' . $key)) ?>">
      <?= Icon::svg($icon, 19) ?>
      <span><?= Util::h($name) ?></span>
    </a>
  <?php endforeach; ?>
</nav>

<main class="portal__inhalt">
  <?php foreach (App::meldungen() as $m): ?>
    <div class="hinweis hinweis--<?= $m['typ'] === 'fehler' ? 'gefahr' : ($m['typ'] === 'info' ? 'still' : 'erfolg') ?> mb-4">
      <?= Icon::svg($m['typ'] === 'fehler' ? 'alert' : 'check', 17) ?>
      <div class="hinweis__text"><?= Util::h($m['text']) ?></div>
    </div>
  <?php endforeach; ?>
  <h1 class="portal__titel"><?= Util::h($titel) ?></h1>
