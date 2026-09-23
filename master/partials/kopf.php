<?php
/**
 * Rahmen jeder Seite der Betreiberzentrale.
 *
 * Dieselbe Familie wie die Anwendung – Schrift, Raster, Bausteine –,
 * aber unverwechselbar: Die Leiste ist dunkler und ruhiger, oben steht
 * „Master", und es gibt keinen Arbeitsplatz eines Kunden, dessen Name
 * dort stehen könnte. Wer hier ist, soll nie glauben, er sei in einer
 * Instanz.
 *
 * Vor dem Einbinden kann die Seite setzen: $titel, $unter, $aktionen,
 * $brotkrumen, $bereich, $inhaltKlasse.
 */
if (!defined('GP_ROOT')) {
    exit;
}
$titel      = $titel ?? 'Betreiberzentrale';
$unter      = $unter ?? '';
$aktionen   = $aktionen ?? '';
$brotkrumen = $brotkrumen ?? [];
$bereich    = $bereich ?? '';
$kSupport   = Support::daten();

$kNavi = [
    ['uebersicht',    'Übersicht',     '/master/',                'dashboard'],
    ['instanzen',     'Instanzen',     '/master/instanzen.php',   'building'],
    ['pakete',        'Pakete',        '/master/pakete.php',      'layers'],
    ['benutzer',      'Benutzer',      '/master/benutzer.php',    'customers'],
    ['auswertung',    'Auswertung',    '/master/auswertung.php',  'analytics'],
    ['aktivitaet',    'Aktivität',     '/master/aktivitaet.php',  'activity'],
    ['protokoll',     'Audit-Log',     '/master/protokoll.php',   'shield'],
    ['einstellungen', 'Einstellungen', '/master/einstellungen.php', 'settings'],
    ['system',        'System',        '/master/system.php',      'monitor'],
];
?>
<!DOCTYPE html>
<html lang="de" data-theme="hell">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<meta name="referrer" content="same-origin">
<title><?= Util::h($titel) ?> · <?= Marke::NAME ?> Master</title>
<link rel="stylesheet" href="<?= Util::attr(App::asset('assets/css/schriften.css')) ?>">
<link rel="stylesheet" href="<?= Util::attr(App::asset('assets/css/app.css')) ?>">
<link rel="icon" href="<?= Util::attr(Marke::favicon()) ?>">
<script>
(function(){try{var t=localStorage.getItem('gp-thema')||'system';
var d=t==='dunkel'||(t==='system'&&matchMedia('(prefers-color-scheme: dark)').matches);
document.documentElement.setAttribute('data-theme',d?'dunkel':'hell');}catch(e){}})();
</script>
</head>
<body class="ist-master">
<div class="verdunkler" data-menue-zu></div>
<div class="app">

<aside class="seitenleiste seitenleiste--master" aria-label="Betreiberzentrale">
  <div class="marke">
    <span class="marke__zeichen"><?= Marke::zeichen(26) ?></span>
    <span class="marke__text">
      <span class="marke__name"><?= Marke::NAME ?></span>
      <span class="marke__plan">Betreiberzentrale</span>
    </span>
    <span class="master-marke" title="Du arbeitest in der Betreiberzentrale, über allen Instanzen.">Master</span>
    <button class="rundknopf nur-mobil" data-menue-zu aria-label="Menü schließen"><?= Icon::svg('x', 18) ?></button>
  </div>

  <nav class="navi" aria-label="Hauptmenü der Betreiberzentrale">
    <div class="navi__gruppe">
      <?php foreach ($kNavi as [$kKey, $kName, $kZiel, $kIcon]): ?>
        <a class="navi__link<?= $bereich === $kKey ? ' ist-aktiv' : '' ?>" href="<?= Util::attr(App::url($kZiel)) ?>"
           <?= $bereich === $kKey ? 'aria-current="page"' : '' ?>>
          <?= Icon::svg($kIcon, 17) ?><span><?= Util::h($kName) ?></span>
        </a>
      <?php endforeach; ?>
    </div>
  </nav>

  <div class="seitenleiste__fuss">
    <div class="aufklapp" style="display:block">
      <button class="profil" data-aufklapp aria-haspopup="true">
        <span class="avatar avatar--klein"><?= Util::h(Util::initialen(Betreiber::name())) ?></span>
        <span class="profil__text">
          <span class="profil__name"><?= Util::h(Betreiber::name()) ?></span>
          <span class="profil__rolle"><?= Util::h(Betreiber::rollenName()) ?></span>
        </span>
        <?= Icon::svg('chevron-up', 15) ?>
      </button>
      <div class="aufklapp__menue aufklapp__menue--links aufklapp__menue--oben" style="min-width:230px">
        <div class="aufklapp__titel"><?= Util::h((string) (Betreiber::benutzer()['email'] ?? '')) ?></div>
        <div class="aufklapp__titel">Darstellung</div>
        <div style="display:flex;gap:2px;padding:0 5px 5px">
          <button class="segment__teil" data-thema-wert="hell" style="flex:1"><?= Icon::svg('sun', 15) ?> Hell</button>
          <button class="segment__teil" data-thema-wert="dunkel" style="flex:1"><?= Icon::svg('moon', 15) ?> Dunkel</button>
          <button class="segment__teil" data-thema-wert="system" style="flex:1">Auto</button>
        </div>
        <div class="aufklapp__trenner"></div>
        <a class="aufklapp__eintrag aufklapp__eintrag--gefahr" href="<?= Util::attr(App::url('/abmelden.php')) ?>">
          <?= Icon::svg('logout', 16) ?> Abmelden</a>
      </div>
    </div>
    <div class="leiste-marke">
      <?= Marke::zeichen(15) ?>
      <span class="leiste-marke__name"><?= Marke::NAME ?> Master</span>
      <span class="leiste-marke__version"><?= Util::h(GP_VERSION) ?></span>
    </div>
  </div>
</aside>

<div class="haupt">
  <?php if ($kSupport !== null): ?>
    <div class="support-leiste" role="status">
      <span class="support-leiste__marke">Support Mode</span>
      <span class="support-leiste__text">Deine Support-Sitzung in
        „<?= Util::h((string) (DB::value('SELECT name FROM workspaces WHERE id = :id', ['id' => (int) $kSupport['workspace_id']], '') ?: '—')) ?>"
        läuft noch <?= Support::restMinuten() ?> Min.</span>
      <a class="btn btn--klein" href="<?= Util::attr(App::url('/app/')) ?>">Zur Instanz</a>
      <form method="post" action="<?= Util::attr(App::url('/master/support.php')) ?>">
        <?= Auth::csrfFeld() ?><input type="hidden" name="aktion" value="ende">
        <button class="btn btn--klein btn--primaer" type="submit">Support Mode beenden</button>
      </form>
    </div>
  <?php endif; ?>

  <header class="kopf">
    <button class="rundknopf mobil-knopf" data-menue-auf aria-label="Menü öffnen"><?= Icon::svg('menu', 19) ?></button>
    <button class="suchknopf" data-palette>
      <?= Icon::svg('search', 16) ?>
      <span>Instanz, Person oder Bereich…</span>
      <span class="suchknopf__taste"><kbd>⌘</kbd><kbd>K</kbd></span>
    </button>
    <div class="kopf__rechts">
      <a class="btn btn--primaer btn--klein" href="<?= Util::attr(App::url('/master/instanz-neu.php')) ?>">
        <?= Icon::svg('plus', 15) ?> <span class="nicht-mobil">Neue Instanz</span></a>
      <button class="rundknopf tipp" data-thema-um data-tipp="Hell / Dunkel" aria-label="Darstellung wechseln">
        <?= Icon::svg('sun', 18) ?>
      </button>
    </div>
  </header>

  <main class="inhalt <?= Util::attr($inhaltKlasse ?? '') ?>" id="inhalt">
    <?php $kMeldungen = App::meldungen(); if ($kMeldungen !== []): ?>
      <div class="meldungen">
        <?php foreach ($kMeldungen as $kM):
          $kIcons = ['erfolg' => 'check', 'fehler' => 'alert', 'warnung' => 'alert', 'info' => 'info']; ?>
          <div class="meldung meldung--<?= Util::attr($kM['typ']) ?>">
            <?= Icon::svg($kIcons[$kM['typ']] ?? 'info', 17) ?>
            <div class="meldung__text"><?= Util::h($kM['text']) ?></div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <?php if ($brotkrumen !== []): ?>
      <nav class="brotkrumen" aria-label="Brotkrumen">
        <?php foreach ($brotkrumen as $kI => [$kName, $kUrl]): ?>
          <?php if ($kI > 0): ?><?= Icon::svg('chevron-right', 13) ?><?php endif; ?>
          <?php if ($kUrl): ?>
            <a href="<?= Util::attr(App::url($kUrl)) ?>"><?= Util::h($kName) ?></a>
          <?php else: ?>
            <span aria-current="page"><?= Util::h($kName) ?></span>
          <?php endif; ?>
        <?php endforeach; ?>
      </nav>
    <?php endif; ?>
    <div class="seitenkopf">
      <div class="seitenkopf__text">
        <h1><?= Util::h($titel) ?></h1>
        <?php if ($unter !== ''): ?><div class="seitenkopf__unter"><?= $unter ?></div><?php endif; ?>
      </div>
      <?php if ($aktionen !== ''): ?>
        <div class="seitenkopf__aktionen"><?= $aktionen ?></div>
      <?php endif; ?>
    </div>
