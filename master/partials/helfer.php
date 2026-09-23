<?php
/**
 * Kleine Ausgabebausteine der Betreiberzentrale.
 */
if (!defined('GP_ROOT')) {
    exit;
}

/** Adresse mit geänderten Parametern – für Filter, Sortierung, Seiten. */
function masterUrl(string $pfad, array $parameter, array $aenderung = []): string
{
    $alle = array_filter($aenderung + $parameter, static fn ($v) => $v !== '' && $v !== null && $v !== 0);
    return App::url($pfad) . ($alle !== [] ? '?' . http_build_query($alle) : '');
}

/** Spaltenkopf, der die Sortierung umschaltet. */
function sortKopf(string $label, string $key, array $f, string $pfad, string $klasse = ''): string
{
    $aktiv = $f['sort'] === $key;
    $richtung = $aktiv && $f['richtung'] === 'asc' ? 'desc' : 'asc';
    $zeichen = $aktiv ? ($f['richtung'] === 'asc' ? ' ↑' : ' ↓') : '';
    return '<th class="' . Util::attr($klasse) . '"' . ($aktiv ? ' aria-sort="' . ($f['richtung'] === 'asc' ? 'ascending' : 'descending') . '"' : '')
         . '><a class="sortkopf' . ($aktiv ? ' ist-aktiv' : '') . '" href="'
         . Util::attr(masterUrl($pfad, $f, ['sort' => $key, 'richtung' => $richtung, 'seite' => ''])) . '">'
         . Util::h($label) . $zeichen . '</a></th>';
}

/** „vor 3 Tagen" mit dem genauen Zeitpunkt im Tooltip. */
function wann(?string $zeit, string $leer = 'nie'): string
{
    if ($zeit === null || $zeit === '') {
        return '<span class="gedimmt">' . Util::h($leer) . '</span>';
    }
    return '<time datetime="' . Util::attr(str_replace(' ', 'T', $zeit)) . '" title="' . Util::attr(Util::datumZeit($zeit)) . '">'
         . Util::h(Util::relativ($zeit)) . '</time>';
}

function paketPille(string $plan): string
{
    $p = Pakete::finden($plan);
    return $p === null
        ? '<span class="pille pille--offen">Nicht zugeordnet</span>'
        : '<span class="pille pille--marke">' . Util::h((string) $p['name']) . '</span>';
}

function demoPille(array $ws): string
{
    return (int) ($ws['demo'] ?? 0) === 1
        ? ' <span class="pille pille--offen" title="Demo-Instanz mit erfundenen Daten – zählt in keiner Plattformzahl mit.">Demo</span>'
        : '';
}

/** Vertragsdaten in einem Satz. */
function aboText(?array $abo): string
{
    return Abos::beschreibung($abo);
}

/** Beschriftete Zeile in einer Definitionsliste. */
function angabe(string $label, string $wertHtml): string
{
    return '<div class="angabe"><dt>' . Util::h($label) . '</dt><dd>' . $wertHtml . '</dd></div>';
}

function schwerePille(string $schwere): string
{
    return pille(Betreiberlog::SCHWERE[$schwere] ?? $schwere,
        ['INFO' => 'offen', 'WARNING' => 'warnung', 'CRITICAL' => 'gefahr'][$schwere] ?? 'offen');
}
