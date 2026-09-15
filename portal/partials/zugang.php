<?php
/**
 * Der Rahmen der Seiten vor der Anmeldung: Anmelden und Registrieren.
 *
 * Eine schmale Spalte, das Zeichen des Betriebs darüber, sonst nichts.
 * Wer hier landet, will genau eine Sache erledigen; jede Navigation, jeder
 * zweite Knopf wäre eine Gelegenheit, es doch nicht zu tun.
 *
 * Absichtlich eine Funktion statt einer Klasse – sie wird aus Vorlagen
 * aufgerufen, und beide Seiten teilen sich damit denselben Kopf, statt ihn
 * zweimal zu pflegen und beim dritten Mal auseinanderlaufen zu lassen.
 */
if (!defined('GP_ROOT')) {
    exit;
}

/**
 * Gibt die Seite aus und beendet die Anfrage.
 *
 * @param string $inhalt fertiges HTML für die Spalte
 */
function zugangSeite(string $titel, string $unter, string $inhalt): never
{
    $branding = Tenant::gesetzt() ? Tenant::branding() : ['primaer' => '#1d6f4a', 'akzent' => '#c8a44d'];
    $name     = Tenant::gesetzt() ? Tenant::name() : 'Kundenportal';
    $farbe    = (string) $branding['primaer'];
    ?><!DOCTYPE html>
<html lang="de" data-theme="hell">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= Util::h($titel) ?> · <?= Util::h($name) ?></title>
<?php /* Schriften vom eigenen Server – wie im übrigen Portal. */ ?>
<link rel="stylesheet" href="<?= Util::attr(App::asset('assets/css/schriften.css')) ?>">
<link rel="stylesheet" href="<?= Util::attr(App::asset('assets/css/app.css')) ?>">
<link rel="stylesheet" href="<?= Util::attr(App::asset('assets/css/portal.css')) ?>">
<style>:root{--marke:<?= Util::attr($farbe) ?>;
  --marke-hell: color-mix(in srgb, <?= Util::attr($farbe) ?> 10%, #fff);}</style>
</head>
<body class="portal">
<main class="portal__inhalt portal__zugang">
  <div class="mitte mb-5">
    <span class="marke__zeichen portal__zugang-zeichen"><?= Util::h(mb_substr($name, 0, 1)) ?></span>
    <h1 class="portal__zugang-titel"><?= Util::h($titel) ?></h1>
    <?php if ($unter !== ''): ?>
      <p class="gedimmt klein mt-2"><?= Util::h($unter) ?></p>
    <?php endif; ?>
  </div>
  <?= $inhalt ?>
</main>
</body></html>
<?php
    exit;
}
