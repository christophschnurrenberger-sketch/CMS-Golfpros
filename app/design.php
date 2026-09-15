<?php
/**
 * Design – die Marke des Pros in wenigen Werten.
 *
 * Bewusst wenige Einstellungen: eine Farbe, ein Akzent, eine Schrift, eine
 * Rundung, ein Stil. Wer dreißig Regler bekommt, baut sich eine Website,
 * die nach dreißig Reglern aussieht.
 */
require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/partials/helfer.php';

Auth::fordern('modul.website');

if (App::istPost()) {
    Auth::csrfFordern();
    Auth::fordern('website.write');

    if (App::aktion() === 'speichern') {
        Tenant::aktualisieren(['branding' => Util::json([
            'primaer' => App::post('primaer', '#2e6a3e'),
            'akzent'  => App::post('akzent', '#b08829'),
            'schrift' => App::post('schrift', 'Archivo'),
            'radius'  => max(0, min(28, App::postInt('radius', 14))),
            'stil'    => App::post('stil', 'modern'),
        ])]);
        Audit::schreiben('geaendert', 'branding', 0, 'Design angepasst');
        App::melden('Design gespeichert.');
    }

    if (App::aktion() === 'logo' && isset($_FILES['logo'])) {
        $datei = $_FILES['logo'];
        $erlaubt = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/svg+xml' => 'svg', 'image/webp' => 'webp'];
        $typ = (string) ($datei['type'] ?? '');
        if ((int) $datei['error'] === UPLOAD_ERR_OK && isset($erlaubt[$typ]) && (int) $datei['size'] < 3_000_000) {
            $ordner = GP_ROOT . '/uploads/w' . Tenant::id();
            if (!is_dir($ordner)) {
                @mkdir($ordner, 0750, true);
            }
            $name = 'logo-' . Util::token(4) . '.' . $erlaubt[$typ];
            if (move_uploaded_file((string) $datei['tmp_name'], $ordner . '/' . $name)) {
                Tenant::aktualisieren(['logo' => 'uploads/w' . Tenant::id() . '/' . $name]);
                App::melden('Logo hochgeladen.');
            }
        } else {
            App::melden('Bitte PNG, JPG, SVG oder WebP unter 3 MB hochladen.', 'fehler');
        }
    }

    if (App::aktion() === 'logo_weg') {
        Tenant::aktualisieren(['logo' => '']);
        App::melden('Logo entfernt.');
    }
    App::weiter('/app/design.php');
}

$b = Tenant::branding();
$logo = (string) (Tenant::workspace()['logo'] ?? '');

$farbvorschlaege = [
    '#0d6b4f' => 'Tiefes Grün', '#14513c' => 'Waldgrün', '#1d5fa8' => 'Marineblau',
    '#0f766e' => 'Petrol', '#1a1a18' => 'Anthrazit', '#7c2d3e' => 'Bordeaux',
    '#b08829' => 'Messing', '#3f4a5c' => 'Schiefer',
];

$titel = 'Design';
$unter = 'Farben, Schrift und Stil deiner Website. Die Anwendung selbst bleibt neutral.';
$brotkrumen = [['Website', '/app/website.php'], ['Design', null]];
require __DIR__ . '/partials/kopf.php';
?>

<div class="raster raster--haupt-neben">
  <form method="post" class="stapel">
    <?= Auth::csrfFeld() ?>
    <input type="hidden" name="aktion" value="speichern">

    <div class="karte">
      <div class="karte__kopf"><h2>Farben</h2></div>
      <div class="karte__koerper">
        <div class="feld">
          <label class="feld__label" for="primaer">Markenfarbe</label>
          <div class="reihe reihe--eng mb-3">
            <input type="color" id="primaer" name="primaer" value="<?= Util::attr((string) $b['primaer']) ?>"
                   style="width:52px;height:38px;padding:3px;border-radius:8px;border:1px solid var(--rand-2);background:var(--flaeche)"
                   oninput="document.getElementById('primaer-text').value=this.value;vorschau()">
            <input class="eingabe mono" id="primaer-text" value="<?= Util::attr((string) $b['primaer']) ?>"
                   oninput="document.getElementById('primaer').value=this.value;vorschau()"
                   style="max-width:130px" aria-label="Farbwert">
            <div class="fueller"></div>
          </div>
          <div class="reihe reihe--eng reihe--umbruch">
            <?php foreach ($farbvorschlaege as $hex => $name): ?>
              <button type="button" class="tipp" data-tipp="<?= Util::attr($name) ?>"
                      onclick="document.getElementById('primaer').value='<?= $hex ?>';document.getElementById('primaer-text').value='<?= $hex ?>';vorschau()"
                      style="width:30px;height:30px;border-radius:9px;background:<?= $hex ?>;
                             border:1px solid var(--rand-2);cursor:pointer"
                      aria-label="<?= Util::attr($name) ?>"></button>
            <?php endforeach; ?>
          </div>
          <div class="feld__hinweis">Wird für Knöpfe, Überschriftsakzente und Verweise verwendet.</div>
        </div>

        <div class="feld">
          <label class="feld__label" for="akzent">Akzentfarbe</label>
          <div class="reihe reihe--eng">
            <input type="color" id="akzent" name="akzent" value="<?= Util::attr((string) $b['akzent']) ?>"
                   style="width:52px;height:38px;padding:3px;border-radius:8px;border:1px solid var(--rand-2);background:var(--flaeche)"
                   oninput="document.getElementById('akzent-text').value=this.value;vorschau()">
            <input class="eingabe mono" id="akzent-text" value="<?= Util::attr((string) $b['akzent']) ?>"
                   oninput="document.getElementById('akzent').value=this.value;vorschau()"
                   style="max-width:130px" aria-label="Akzentfarbwert">
          </div>
          <div class="feld__hinweis">Sparsam eingesetzt: Sterne, Hervorhebungen, kleine Marken.</div>
        </div>
      </div>
    </div>

    <div class="karte">
      <div class="karte__kopf"><h2>Schrift und Form</h2></div>
      <div class="karte__koerper">
        <div class="feld">
          <label class="feld__label" for="schrift">Schrift</label>
          <select id="schrift" name="schrift" onchange="vorschau()">
            <?php foreach (Website::SCHRIFTEN as $k => $name): ?>
              <option value="<?= Util::attr($k) ?>"<?= (string) $b['schrift'] === $k ? ' selected' : '' ?>>
                <?= Util::h($name) ?><?= Website::vomEigenenServer($k) ? '' : ' · von Google' ?></option>
            <?php endforeach; ?>
          </select>
          <?php /*
           * Der Hinweis steht bewusst hier und nicht im Kleingedruckten: Die
           * Entscheidung fällt in diesem Auswahlfeld, und wer sie trifft,
           * soll wissen, was sie bedeutet. LG München I, 20.01.2022,
           * Az. 3 O 17493/20.
           */ ?>
          <div class="feld__hinweis">
            Archivo liegt auf deinem Server. Die anderen Schriften lädt der
            Browser deines Besuchers bei Google – dabei geht seine
            IP-Adresse dorthin, bevor er etwas angeklickt hat. Das gehört
            dann in die Datenschutzerklärung.
          </div>
        </div>
        <div class="feld">
          <label class="feld__label" for="radius">Rundung: <span id="radius-wert"><?= (int) $b['radius'] ?></span> px</label>
          <input type="range" id="radius" name="radius" min="0" max="28" step="2"
                 value="<?= (int) $b['radius'] ?>" style="width:100%"
                 oninput="document.getElementById('radius-wert').textContent=this.value;vorschau()">
          <div class="feld__hinweis">0 = kantig und streng, 20 = weich und freundlich.</div>
        </div>
        <div class="feld">
          <span class="feld__label">Stil</span>
          <div class="wahl-karten">
            <?php foreach (Website::STILE as $k => [$name, $text]): ?>
              <label class="wahl-karte">
                <input type="radio" name="stil" value="<?= $k ?>"
                       <?= (string) ($b['stil'] ?? 'modern') === $k ? ' checked' : '' ?> onchange="vorschau()">
                <span class="wahl-karte__titel"><?= Util::h($name) ?></span>
                <span class="wahl-karte__text"><?= Util::h($text) ?></span>
              </label>
            <?php endforeach; ?>
          </div>
          <div class="feld__hinweis">
            „Klassisch“ setzt die Überschriften in Libre Baskerville und holt
            diese Schrift ebenfalls bei Google.
          </div>
        </div>
      </div>
      <?php if (Auth::darf('website.write')): ?>
        <div class="karte__fuss"><div class="fueller"></div>
          <button class="btn btn--primaer" type="submit">Design speichern</button></div>
      <?php endif; ?>
    </div>
  </form>

  <div class="stapel">
    <div class="karte">
      <div class="karte__kopf"><h3>Logo</h3></div>
      <div class="karte__koerper">
        <?php if ($logo !== '' && is_file(GP_ROOT . '/' . ltrim($logo, '/'))): ?>
          <div style="padding:var(--r5);background:var(--flaeche-2);border-radius:var(--radius);
                      display:grid;place-items:center;margin-bottom:var(--r3)">
            <img src="<?= Util::attr(App::url($logo)) ?>" alt="Logo" style="max-height:64px;max-width:100%">
          </div>
          <form method="post">
            <?= Auth::csrfFeld() ?>
            <input type="hidden" name="aktion" value="logo_weg">
            <button class="btn btn--voll btn--klein" type="submit">Logo entfernen</button>
          </form>
        <?php else: ?>
          <form method="post" enctype="multipart/form-data">
            <?= Auth::csrfFeld() ?>
            <input type="hidden" name="aktion" value="logo">
            <label class="ablage" for="logo-datei">
              <div class="ablage__symbol"><?= Icon::svg('image', 22) ?></div>
              <div class="halbfett klein">Logo hochladen</div>
              <div class="winzig gedimmt">PNG, SVG oder WebP · bis 3 MB</div>
              <input id="logo-datei" type="file" name="logo" accept="image/png,image/jpeg,image/svg+xml,image/webp"
                     style="position:absolute;opacity:0;width:1px;height:1px"
                     onchange="this.form.submit()">
            </label>
          </form>
        <?php endif; ?>
      </div>
    </div>

    <div class="karte">
      <div class="karte__kopf"><h3>Vorschau</h3></div>
      <?php /*
       * Die Vorschau zeigt denselben Entwurf wie die echte Seite: warmes
       * Papier, sehr große Überschrift, gelber Marker, Handschrift als
       * zweite Stimme, eine abweichende Ecke. Eine hübschere, aber andere
       * Vorschau wäre schlimmer als gar keine – dann wählt der Pro seine
       * Farbe an einem Bild aus, das es nicht gibt.
       */ ?>
      <div class="karte__koerper" id="vorschau-flaeche"
           style="--v-marke:<?= Util::attr((string) $b['primaer']) ?>;
                  --v-akzent:<?= Util::attr((string) $b['akzent']) ?>;
                  --v-radius:<?= (int) $b['radius'] ?>px;
                  --v-ecke:calc(var(--v-radius) * 2.1);
                  --v-ecke-klein:calc(var(--v-radius) * .43);
                  --v-ecke-knopf:calc(var(--v-radius) * 1.8);
                  background:#fbf8f0;color:#17271c;
                  border-radius:var(--v-ecke) var(--v-ecke) var(--v-ecke) var(--v-ecke-klein)">

        <div style="display:flex;align-items:flex-start;gap:10px;margin-bottom:22px">
          <div style="min-width:0">
            <div id="vorschau-marke" style="font-size:15px;font-weight:600;letter-spacing:-.03em;
                 line-height:1.05"><?= Util::h(Tenant::name()) ?></div>
            <div class="vorschau-hand" style="font-size:14px;color:var(--v-marke)">Golfschule</div>
          </div>
          <span class="fueller"></span>
          <span style="background:var(--v-marke);color:#fbf8f0;padding:7px 15px;
                       border-radius:var(--v-ecke-knopf);font-size:12px;font-weight:600;
                       white-space:nowrap">Termin buchen</span>
        </div>

        <div id="vorschau-titel" style="font-size:31px;font-weight:500;letter-spacing:-.035em;
             line-height:.98;margin-bottom:14px">In acht Wochen<br>zur Platzreife.<br>
          <span id="vorschau-marker" style="background:var(--v-akzent);border-radius:7px;
                padding:0 .08em .04em">Ohne Umwege.</span></div>

        <p style="font-size:13.5px;line-height:1.65;color:#45524a;margin:0 0 18px;max-width:34ch">
          Vom ersten Schlag bis auf den Platz – in kleinen Gruppen, mit festem Ablauf.</p>

        <div class="reihe reihe--eng" style="align-items:center;gap:18px">
          <span style="background:var(--v-marke);color:#fbf8f0;padding:11px 22px;
                       border-radius:var(--v-ecke-knopf);font-size:13.5px;font-weight:600">
            Freie Termine ansehen</span>
          <span style="font-size:13.5px;font-weight:560;border-bottom:1.5px solid currentColor;
                       padding-bottom:2px">Preise</span>
        </div>

        <div style="display:flex;align-items:flex-end;gap:14px;margin-top:24px;
                    padding-top:20px;border-top:1px solid rgba(23,39,28,.16)">
          <div style="flex:none;width:58px;height:58px;border-radius:0 50% 50% 50%;
                      background:repeating-linear-gradient(107deg,#e3e5ce 0 7px,#edefdc 7px 14px)"></div>
          <div class="vorschau-hand" style="font-size:17px;color:var(--v-marke);line-height:1.25">
            Erste Stunde? Schläger<br>stelle ich.</div>
        </div>
      </div>
    </div>

    <div class="hinweis hinweis--still">
      <?= Icon::svg('info', 17) ?>
      <div class="hinweis__text">Das Design gilt für deine öffentliche Website, das Kundenportal,
        Newsletter und PDF-Belege. Der Arbeitsbereich hier bleibt neutral – er ist Werkzeug,
        nicht Werbefläche.</div>
    </div>
  </div>
</div>

<style>
/* Die zweite Stimme des Entwurfs. Caveat liegt bereits im Kopf bereit. */
.vorschau-hand { font-family: "Caveat", "Segoe Script", cursive; }
</style>

<script>
function vorschau() {
  var f = document.getElementById('vorschau-flaeche');
  f.style.setProperty('--v-marke', document.getElementById('primaer').value);
  f.style.setProperty('--v-akzent', document.getElementById('akzent').value);
  f.style.setProperty('--v-radius', document.getElementById('radius').value + 'px');

  var stil = document.querySelector('input[name="stil"]:checked');
  var schrift = document.getElementById('schrift').value;
  var titel = document.getElementById('vorschau-titel');

  /* Dieselbe Regel wie in Website::stilVariablen(): nur „klassisch“ tauscht
     die Titelschrift; „kraftvoll“ macht sie schwerer, nicht anders. */
  titel.style.fontFamily = (stil && stil.value === 'klassisch')
    ? '"Libre Baskerville", Georgia, serif' : '"' + schrift + '", system-ui, sans-serif';
  titel.style.fontWeight = (stil && stil.value === 'kraftvoll') ? '660' : '500';
  document.getElementById('vorschau-marke').style.fontFamily =
    '"' + schrift + '", system-ui, sans-serif';
}
</script>

<?php require __DIR__ . '/partials/fuss.php'; ?>
