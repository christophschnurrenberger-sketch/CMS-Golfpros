<?php
require __DIR__ . '/lib/bootstrap.php';

$host = strtolower(preg_replace('/^www\./', '', (string) ($_SERVER['HTTP_HOST'] ?? '')) ?? '');
$workspace = Tenant::nachDomain($host);
if (!$workspace) {
    $slug = App::get('w');
    $workspace = $slug !== '' ? Tenant::nachSlug($slug) : Tenant::erster();
}
if (!$workspace) {
    http_response_code(404);
    exit;
}
Tenant::setzen((int) $workspace['id']);

header('Content-Type: application/xml; charset=utf-8');
$basis = (string) $workspace['domain'] !== ''
    ? 'https://' . $workspace['domain']
    : App::absolut('/site.php?w=' . rawurlencode((string) $workspace['slug']));
echo SEO::sitemap(rtrim($basis, '/'));
