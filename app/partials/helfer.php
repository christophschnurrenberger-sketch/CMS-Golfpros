<?php
/**
 * Historischer Pfad der Ausgabebausteine.
 *
 * Die Funktionen liegen inzwischen in lib/vorlage.php, weil auch das
 * Kundenportal sie braucht und dafür nicht in den Anwendungsordner greifen
 * soll. Diese Datei bleibt als Einsprungpunkt bestehen: Sie wird auf jeder
 * Seite der Anwendung eingebunden, und ein require_once verträgt das auch
 * dann, wenn eine Seite sie mehrfach anzieht.
 */
if (!defined('GP_ROOT')) {
    exit;
}
require_once GP_ROOT . '/lib/vorlage.php';
