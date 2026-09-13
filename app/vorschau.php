<?php
/** Schickt auf die öffentliche Website des eigenen Workspace. */
require __DIR__ . '/../lib/bootstrap.php';
Auth::fordern('modul.dashboard');

$start = Pages::startseite();
$slug  = (string) (Tenant::workspace()['slug'] ?? '');
header('Location: ' . ($start ? Pages::url($start) : App::url('/site.php?w=' . rawurlencode($slug))));
exit;
