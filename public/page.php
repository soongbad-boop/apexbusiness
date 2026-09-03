<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';
$slug = trim((string) ($_GET['slug'] ?? ''));
$query = db($config)->prepare('SELECT * FROM pages WHERE slug = ? AND status = \'published\' LIMIT 1');
$query->execute([$slug]);
$page = $query->fetch();
if (!$page) {
    http_response_code(404);
    $page = ['title' => 'Page not found', 'content' => 'The requested page could not be found.', 'seo_title' => 'Page not found', 'seo_description' => 'The requested page could not be found.'];
}
$title = $page['seo_title'] ?: $page['title'];
$pageTitle = stripos($title, 'Apex Business Consultancy') !== false ? $title : $title . ' | Apex Business Consultancy';
$description = $page['seo_description'] ?: '';
?><!doctype html>
<html lang="en"><head><!-- Google tag (gtag.js) --><script async src="https://www.googletagmanager.com/gtag/js?id=G-ZNWDH4V7BB"></script><script>window.dataLayer = window.dataLayer || []; function gtag(){dataLayer.push(arguments);} gtag('js', new Date()); gtag('config', 'G-ZNWDH4V7BB');</script><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><link rel="icon" type="image/svg+xml" href="/favicon.svg"><title><?= e($pageTitle) ?></title><meta name="description" content="<?= e($description) ?>"></head><body><main><article><h1><?= e($page['title']) ?></h1><div><?= nl2br(e($page['content'])) ?></div></article></main></body></html>
