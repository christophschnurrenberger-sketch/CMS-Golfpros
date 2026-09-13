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
<html lang="de" data-theme="hell">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<title><?= Util::h($titel) ?> · <?= Util::h(Tenant::name()) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= Util::attr(App::asset('assets/css/app.css')) ?>">
<link rel="stylesheet" href="<?= Util::attr(App::asset('assets/css/portal.css')) ?>">
<script>
(function(){try{var t=localStorage.getItem('gp-thema')||'system';
var d=t==='dunkel'||(t==='system'&&matchMedia('(prefers-color-scheme: dark)').matches);
document.documentElement.setAttribute('data-theme',d?'dunkel':'hell');}catch(e){}})();
</script>
<style>
:root{
  --marke: <?= Util::attr((string) $branding['primaer']) ?>;
  --marke-dunkel: color-mix(in srgb, <?= Util::attr((string) $branding['primaer']) ?> 82%, #000);
  --marke-hell: color-mix(in srgb, <?= Util::attr((string) $branding['primaer']) ?> 10%, #fff);
  --marke-rand: color-mix(in srgb, <?= Util::attr((string) $branding['primaer']) ?> 28%, #fff);
  --akzent: <?= Util::attr((string) $branding['akzent']) ?>;
}
[data-theme="dunkel"]{
  --marke-hell: color-mix(in srgb, <?= Util::attr((string) $branding['primaer']) ?> 22%, #0b0d0e);
  --marke-rand: color-mix(in srgb, <?= Util::attr((string) $branding['primaer']) ?> 42%, #0b0d0e);
}
</style>
</head>
<body class="portal">

<header class="portal__kopf">
  <a class="portal__marke" href="<?= Util::attr(App::url('/portal/')) ?>">
    <span class="marke__zeichen">
      <?php if ($logo !== '' && is_file(GP_ROOT . '/' . ltrim($logo, '/'))): ?>
        <img src="<?= Util::attr(App::url($logo)) ?>" alt="">
      <?php else: ?>
        <?= Util::h(mb_substr(Tenant::name(), 0, 1)) ?>
      <?php endif; ?>
    </span>
    <span class="portal__marke-text"><?= Util::h(Tenant::name()) ?></span>
  </a>
  <div class="fueller"></div>
  <button class="rundknopf" data-thema-um aria-label="Darstellung wechseln"><?= Icon::svg('sun', 17) ?></button>
  <a class="portal__ich" href="<?= Util::attr(App::url('/portal/?ansicht=profil')) ?>"
     aria-label="Mein Profil">
    <span class="avatar avatar--klein" style="background:<?= Util::attr(Util::avatarFarbe(Customers::name($kunde))) ?>">
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
