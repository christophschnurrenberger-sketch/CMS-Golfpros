<?php
/**
 * Gemeinsamer Anfang jeder Seite unter /master/.
 *
 * Die Tür steht hier und nicht in jeder Seite einzeln: Wer eine neue
 * Seite anlegt und diese Datei einbindet, kann die Prüfung nicht
 * vergessen. Eine Seite, die mehr verlangt, ruft danach zusätzlich
 * Betreiber::fordern() mit ihrem eigenen Recht.
 *
 * $masterJson = true vor dem Einbinden: Antworten als JSON statt Seite.
 */
require dirname(__DIR__, 2) . '/lib/bootstrap.php';
require_once GP_ROOT . '/lib/vorlage.php';
require_once __DIR__ . '/helfer.php';

Betreiber::fordern('zentrale.view', !empty($masterJson));

/* Die Betreiberzentrale hat keinen Mandanten. Wer aus dem Support Mode
   hierher kommt, trägt noch den der Instanz – der gilt hier nicht. */
Tenant::setzen(0);
