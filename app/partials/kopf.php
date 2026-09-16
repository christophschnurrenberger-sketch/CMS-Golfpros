<?php
/**
 * Der Rahmen jeder Seite der Anwendung.
 *
 * Vor dem Einbinden kann die Seite setzen:
 *   $titel        Überschrift und Browsertitel
 *   $unter        Zeile unter der Überschrift
 *   $aktionen     HTML für die Knöpfe rechts oben
 *   $brotkrumen   [['Kunden', '/app/kunden.php'], ['Thomas Berger', null]]
 *   $inhaltKlasse zusätzliche Klasse für den Inhaltsbereich
 *   $ohneKopf     true blendet den Seitenkopf aus (z. B. Baukasten)
 */
if (!defined('GP_ROOT')) {
    exit;
}

$benutzer   = Auth::benutzer();
$branding   = Tenant::branding();
$bereich    = $bereich ?? App::bereich();
$titel      = $titel ?? Module::name($bereich);
$unter      = $unter ?? '';
$aktionen   = $aktionen ?? '';
$brotkrumen = $brotkrumen ?? [];
$ungelesen  = Notify::ungelesen(Auth::id());
$logo       = (string) (Tenant::workspace()['logo'] ?? '');
?>
<!DOCTYPE html>
<html lang="de" data-theme="hell">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<title><?= Util::h($titel) ?> · <?= Util::h(Tenant::name()) ?></title>
<?php /* Schrift vom eigenen Server: keine Verbindung zu Google beim Anmelden. */ ?>
<link rel="stylesheet" href="<?= Util::attr(App::asset('assets/css/schriften.css')) ?>">
<link rel="stylesheet" href="<?= Util::attr(App::asset('assets/css/app.css')) ?>">
<link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'><rect width='32' height='32' rx='8' fill='%230d6b4f'/><text x='16' y='22' font-family='Helvetica' font-size='16' font-weight='bold' fill='white' text-anchor='middle'>G</text></svg>">
<script>
/* Thema vor dem ersten Anstrich setzen – sonst blitzt Weiß auf. */
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
<body>
<div class="verdunkler" data-menue-zu></div>
<div class="app">

<aside class="seitenleiste">
  <div class="marke">
    <span class="marke__zeichen">
      <?php if ($logo !== '' && is_file(GP_ROOT . '/' . ltrim($logo, '/'))): ?>
        <img src="<?= Util::attr(App::url($logo)) ?>" alt="">
      <?php else: ?>
        <?= Util::h(mb_substr(Tenant::name(), 0, 1)) ?>
      <?php endif; ?>
    </span>
    <span class="marke__text">
      <span class="marke__name"><?= Util::h(Tenant::name()) ?></span>
      <span class="marke__plan"><?= Util::h(Module::planName(Tenant::plan())) ?></span>
    </span>
    <button class="rundknopf nur-mobil" data-menue-zu aria-label="Menü schließen"><?= Icon::svg('x', 18) ?></button>
  </div>

  <nav class="navi" aria-label="Hauptmenü">
    <?php
    $zaehler = [
        'leads'    => Tenant::count('leads', "stufe NOT IN ('kunde','verloren')"),
        'invoices' => Tenant::count('invoices', "status IN ('offen','ueberfaellig')"),
    ];
    foreach (Module::menue() as $gruppe => $eintraege):
        $gruppenName = Module::gruppenname((string) $gruppe);
    ?>
      <div class="navi__gruppe">
        <?php if ($gruppenName !== ''): ?>
          <div class="navi__titel"><?= Util::h($gruppenName) ?></div>
        <?php endif; ?>
        <?php foreach ($eintraege as $e):
            $ziel = [
                'dashboard' => '/app/', 'customers' => '/app/kunden.php', 'leads' => '/app/leads.php',
                'calendar' => '/app/kalender.php', 'bookings' => '/app/buchungen.php',
                'packages' => '/app/pakete.php',
                'training' => '/app/training.php', 'video' => '/app/videos.php', 'courses' => '/app/kurse.php',
                'products' => '/app/produkte.php', 'payments' => '/app/zahlungen.php',
                'invoices' => '/app/rechnungen.php', 'website' => '/app/website.php',
                'content' => '/app/inhalte.php', 'events' => '/app/events.php',
                'travel' => '/app/reisen.php',
                'marketing' => '/app/marketing.php', 'newsletter' => '/app/newsletter.php',
                'automations' => '/app/automationen.php', 'community' => '/app/community.php',
                'analytics' => '/app/auswertung.php', 'ai' => '/app/ki.php',
                'settings' => '/app/einstellungen.php',
            ][$e['key']] ?? '/app/';
            $zahl = $zaehler[$e['key']] ?? 0;
        ?>
          <a class="navi__link<?= $bereich === $e['key'] ? ' ist-aktiv' : '' ?>" href="<?= Util::attr(App::url($ziel)) ?>">
            <?= Icon::svg((string) $e['icon'], 17) ?>
            <span><?= Util::h((string) $e['name']) ?></span>
            <?php if ($zahl > 0): ?><span class="navi__zahl"><?= $zahl ?></span><?php endif; ?>
          </a>
        <?php endforeach; ?>
      </div>
    <?php endforeach; ?>
  </nav>

  <div class="seitenleiste__fuss">
    <div class="aufklapp" style="display:block">
      <button class="profil" data-aufklapp aria-haspopup="true">
        <span class="avatar avatar--klein" style="background:<?= Util::attr(Util::avatarFarbe(Auth::name())) ?>">
          <?= Util::h(Util::initialen(Auth::name())) ?>
        </span>
        <span class="profil__text">
          <span class="profil__name"><?= Util::h(Auth::name()) ?></span>
          <span class="profil__rolle"><?= Util::h(Auth::rollenName()) ?></span>
        </span>
        <?= Icon::svg('chevron-up', 15) ?>
      </button>
      <div class="aufklapp__menue aufklapp__menue--links aufklapp__menue--oben" style="min-width:230px">
        <div class="aufklapp__titel"><?= Util::h((string) ($benutzer['email'] ?? '')) ?></div>
        <a class="aufklapp__eintrag" href="<?= Util::attr(App::url('/app/profil.php')) ?>">
          <?= Icon::svg('user', 16) ?> Mein Profil</a>
        <?php if (Auth::darf('settings.allgemein')): ?>
        <a class="aufklapp__eintrag" href="<?= Util::attr(App::url('/app/einstellungen.php')) ?>">
          <?= Icon::svg('settings', 16) ?> Einstellungen</a>
        <a class="aufklapp__eintrag" href="<?= Util::attr(App::url('/app/tarif.php')) ?>">
          <?= Icon::svg('layers', 16) ?> Tarif &amp; Module</a>
        <?php endif; ?>
        <div class="aufklapp__trenner"></div>
        <div class="aufklapp__titel">Darstellung</div>
        <div style="display:flex;gap:2px;padding:0 5px 5px">
          <button class="segment__teil" data-thema-wert="hell" style="flex:1"><?= Icon::svg('sun', 15) ?> Hell</button>
          <button class="segment__teil" data-thema-wert="dunkel" style="flex:1"><?= Icon::svg('moon', 15) ?> Dunkel</button>
          <button class="segment__teil" data-thema-wert="system" style="flex:1">Auto</button>
        </div>
        <div class="aufklapp__trenner"></div>
        <a class="aufklapp__eintrag" href="<?= Util::attr(App::url('/app/vorschau.php')) ?>" target="_blank" rel="noopener">
          <?= Icon::svg('external', 16) ?> Website ansehen</a>
        <a class="aufklapp__eintrag aufklapp__eintrag--gefahr" href="<?= Util::attr(App::url('/abmelden.php')) ?>">
          <?= Icon::svg('logout', 16) ?> Abmelden</a>
      </div>
    </div>
  </div>
</aside>

<div class="haupt">
  <header class="kopf">
    <button class="rundknopf mobil-knopf" data-menue-auf aria-label="Menü öffnen"><?= Icon::svg('menu', 19) ?></button>

    <button class="suchknopf" data-palette>
      <?= Icon::svg('search', 16) ?>
      <span>Suchen oder Befehl…</span>
      <span class="suchknopf__taste"><kbd>⌘</kbd><kbd>K</kbd></span>
    </button>

    <div class="kopf__rechts">
      <div class="aufklapp">
        <button class="btn btn--primaer btn--klein" data-aufklapp>
          <?= Icon::svg('plus', 15) ?> <span class="nicht-mobil">Neu</span>
        </button>
        <div class="aufklapp__menue">
          <div class="aufklapp__titel">Schnell anlegen</div>
          <?php
          $schnell = [
              ['Kunde', '/app/kunde.php?id=neu', 'user-plus', 'customers.write'],
              ['Termin', '/app/buchung.php?id=neu', 'calendar', 'bookings.write'],
              ['Rechnung', '/app/rechnung.php?id=neu', 'invoices', 'invoices.write'],
              ['Lead', '/app/lead.php?id=neu', 'leads', 'leads.write'],
              ['Trainingsplan', '/app/trainingsplan.php?id=neu', 'training', 'training.write'],
              ['Seite', '/app/seite.php?id=neu', 'website', 'website.write'],
          ];
          foreach ($schnell as [$name, $url, $icon, $recht]):
              if (!Auth::darf($recht)) { continue; } ?>
            <a class="aufklapp__eintrag" href="<?= Util::attr(App::url($url)) ?>"><?= Icon::svg($icon, 16) ?> <?= Util::h($name) ?></a>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="aufklapp">
        <button class="rundknopf" data-aufklapp aria-label="Benachrichtigungen">
          <?= Icon::svg('bell', 18) ?>
          <?php if ($ungelesen > 0): ?>
            <span class="rundknopf__punkt"><?= $ungelesen > 9 ? '9+' : $ungelesen ?></span>
          <?php endif; ?>
        </button>
        <div class="aufklapp__menue" style="min-width:340px;max-width:min(400px,calc(100vw - 28px))">
          <div class="reihe reihe--zwischen" style="padding:7px 9px 4px">
            <span class="versal gedimmt-2">Benachrichtigungen</span>
            <?php if ($ungelesen > 0): ?>
              <a class="klein" href="<?= Util::attr(App::url('/app/benachrichtigungen.php?aktion=alle_gelesen')) ?>">Alle gelesen</a>
            <?php endif; ?>
          </div>
          <?php
          $meldungenListe = Notify::fuerBenutzer(Auth::id(), 7);
          if ($meldungenListe === []): ?>
            <div style="padding:var(--r5);text-align:center;color:var(--text-3);font-size:13px">
              Nichts Neues. Hier landen Buchungen, Zahlungen und Empfehlungen.
            </div>
          <?php else:
            foreach ($meldungenListe as $m): ?>
            <a class="aufklapp__eintrag" href="<?= Util::attr(App::url(($m['link'] ?: '/app/benachrichtigungen.php'))) ?>"
               style="align-items:flex-start<?= $m['gelesen'] ? '' : ';background:var(--marke-hell)' ?>">
              <?= Icon::svg(Notify::kategorieIcon((string) $m['kategorie']), 16) ?>
              <span style="flex:1;min-width:0">
                <span style="display:block;font-weight:550;color:var(--text)"><?= Util::h((string) $m['titel']) ?></span>
                <?php if ($m['text']): ?>
                  <span class="klein gedimmt" style="display:block"><?= Util::h(Util::kuerzen((string) $m['text'], 72)) ?></span>
                <?php endif; ?>
                <span class="winzig gedimmt-2"><?= Util::h(Util::relativ((string) $m['erstellt'])) ?></span>
              </span>
            </a>
            <?php endforeach;
          endif; ?>
          <div class="aufklapp__trenner"></div>
          <a class="aufklapp__eintrag" href="<?= Util::attr(App::url('/app/benachrichtigungen.php')) ?>">
            <?= Icon::svg('inbox', 16) ?> Alle ansehen</a>
        </div>
      </div>

      <button class="rundknopf tipp" data-thema-um data-tipp="Hell / Dunkel" aria-label="Darstellung wechseln">
        <?= Icon::svg('sun', 18) ?>
      </button>

      <?php if (Auth::darf('ai.use') && Tenant::modul('ai')): ?>
        <a class="rundknopf tipp" href="<?= Util::attr(App::url('/app/ki.php')) ?>" data-tipp="KI-Assistent" aria-label="KI-Assistent">
          <?= Icon::svg('ai', 18) ?>
        </a>
      <?php endif; ?>
    </div>
  </header>

  <main class="inhalt <?= Util::attr($inhaltKlasse ?? '') ?>">
    <?php $meldungen = App::meldungen(); if ($meldungen !== []): ?>
      <div class="meldungen">
        <?php foreach ($meldungen as $m):
          $icons = ['erfolg' => 'check', 'fehler' => 'alert', 'warnung' => 'alert', 'info' => 'info']; ?>
          <div class="meldung meldung--<?= Util::attr($m['typ']) ?>">
            <?= Icon::svg($icons[$m['typ']] ?? 'info', 17) ?>
            <div class="meldung__text"><?= Util::h($m['text']) ?></div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <?php if (empty($ohneKopf)): ?>
      <?php if ($brotkrumen !== []): ?>
        <nav class="brotkrumen">
          <?php foreach ($brotkrumen as $i => [$name, $url]): ?>
            <?php if ($i > 0): ?><?= Icon::svg('chevron-right', 13) ?><?php endif; ?>
            <?php if ($url): ?>
              <a href="<?= Util::attr(App::url($url)) ?>"><?= Util::h($name) ?></a>
            <?php else: ?>
              <span><?= Util::h($name) ?></span>
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
    <?php endif; ?>
