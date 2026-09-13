<?php
/**
 * Die öffentliche Website eines Workspace.
 *
 * Aufgelöst wird in dieser Reihenfolge: eigene Domain, dann ?w=slug,
 * dann – wenn es nur einen Workspace gibt – dieser eine. So funktioniert
 * dieselbe Installation als Einzelplatz und als Mandantensystem, ohne dass
 * jemand etwas umstellt.
 */
require __DIR__ . '/lib/bootstrap.php';

$host = strtolower(preg_replace('/^www\./', '', (string) ($_SERVER['HTTP_HOST'] ?? '')) ?? '');
$workspace = Tenant::nachDomain($host);

if (!$workspace) {
    $slug = App::get('w');
    $workspace = $slug !== '' ? Tenant::nachSlug($slug) : null;
}
if (!$workspace && DB::int('SELECT COUNT(*) FROM workspaces WHERE aktiv = 1') === 1) {
    $workspace = Tenant::erster();
}
if (!$workspace) {
    http_response_code(404);
    exit('Diese Adresse gehört zu keiner Website.');
}
Tenant::setzen((int) $workspace['id']);

/* ------------------------------------------------------------ Beitrag - */

$beitragSlug = App::get('beitrag');
if ($beitragSlug !== '') {
    $beitrag = Tenant::one('posts', "slug = :s AND status = 'veroeffentlicht'", ['s' => $beitragSlug]);
    if (!$beitrag) {
        http_response_code(404);
        $beitrag = null;
    } else {
        Tenant::update('posts', (int) $beitrag['id'], ['aufrufe' => (int) $beitrag['aufrufe'] + 1]);
        Analytics::besuchZaehlen('/blog/' . $beitragSlug, (string) $beitrag['titel']);

        $seo = Util::ausJson((string) $beitrag['seo'], []);
        $pseudoSeite = [
            'id' => 0, 'titel' => (string) $beitrag['titel'], 'slug' => (string) $beitrag['slug'],
            'startseite' => 0,
            'seo' => Util::json([
                'titel' => (string) ($seo['titel'] ?? $beitrag['titel']),
                'beschreibung' => (string) ($seo['beschreibung'] ?? $beitrag['auszug']),
                'bild' => (string) $beitrag['bild'], 'index' => true,
            ]),
        ];

        $inhalt = '<article class="beitrag">'
            . '<div class="beitrag__kopf">'
            . '<div class="beitrag__meta">' . Util::h(Util::datumLang((string) $beitrag['veroeffentlicht']))
            . ' · ' . Util::h(Auth::trainerName((int) $beitrag['user_id'])) . '</div>'
            . '<h1>' . Util::h((string) $beitrag['titel']) . '</h1>'
            . ((string) $beitrag['auszug'] !== ''
               ? '<p class="hero__absatz">' . Util::h((string) $beitrag['auszug']) . '</p>' : '')
            . '</div>'
            . ((string) $beitrag['bild'] !== ''
               ? '<img class="beitrag__bild" src="' . Util::attr(App::url((string) $beitrag['bild'])) . '" alt="">' : '')
            . '<div class="beitrag__text">' . Util::einfachesMarkdown((string) $beitrag['text']) . '</div>'
            . '</article>';

        echo Website::ausgeben($pseudoSeite, $inhalt);
        exit;
    }
}

/* -------------------------------------------------------------- Seite - */

$seitenSlug = App::get('s');
$seite = $seitenSlug !== ''
    ? Tenant::one('pages', "slug = :s AND status = 'veroeffentlicht'", ['s' => $seitenSlug])
    : Pages::startseite();

if (!$seite || (string) $seite['status'] !== 'veroeffentlicht') {
    http_response_code(404);
    $ersatz = ['id' => 0, 'titel' => 'Seite nicht gefunden', 'slug' => '', 'startseite' => 0,
               'seo' => Util::json(['titel' => 'Nicht gefunden', 'index' => false])];
    echo Website::ausgeben($ersatz,
        '<section class="abschnitt"><div class="inhalt-breite inhalt-breite--schmal">'
        . '<h1>Diese Seite gibt es nicht</h1>'
        . '<p class="hero__absatz">Vielleicht wurde sie umbenannt oder entfernt.</p>'
        . '<div class="knopfreihe"><a class="knopf" href="' . Util::attr(App::url('/site.php?w='
          . rawurlencode((string) $workspace['slug']))) . '">Zur Startseite</a></div>'
        . '</div></section>');
    exit;
}

Tenant::update('pages', (int) $seite['id'], ['aufrufe' => (int) $seite['aufrufe'] + 1]);
Analytics::besuchZaehlen(
    (int) $seite['startseite'] === 1 ? '/' : '/' . $seite['slug'],
    (string) $seite['titel']
);

/* Rückmeldungen aus Formular, Buchung und Kauf */
$meldung = '';
if (App::get('gesendet') === '1') {
    $meldung = '<div class="meldung-kasten">' . Icon::svg('check', 17) . ' '
             . Util::h(App::get('text', 'Danke! Ich melde mich in Kürze.')) . '</div>';
} elseif (App::get('fehler') !== '') {
    $meldung = '<div class="meldung-kasten meldung-kasten--fehler">' . Icon::svg('alert', 17) . ' '
             . Util::h(App::get('fehler')) . '</div>';
}

$inhalt = ($meldung !== ''
    ? '<section class="abschnitt" style="padding-bottom:0"><div class="inhalt-breite">' . $meldung . '</div></section>'
    : '') . Renderer::seite(Pages::bloecke($seite));

echo Website::ausgeben($seite, $inhalt);
