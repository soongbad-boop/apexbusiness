<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';
$slug = normalized_text($_GET['slug'] ?? '', 190);
if ($slug === '' || !preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug)) {
    http_response_code(404);
    $page = false;
} else {
    $query = db($config)->prepare("SELECT * FROM pages WHERE slug = ? AND status = 'published' LIMIT 1");
    $query->execute([$slug]);
    $page = $query->fetch();
}

if (!$page) {
    http_response_code(404);
    header('X-Robots-Tag: noindex, nofollow');
    $page = ['title' => 'Page not found', 'content' => '<p>The requested page could not be found.</p>', 'seo_title' => 'Page not found', 'seo_description' => 'The requested page could not be found.'];
}
$title = $page['seo_title'] ?: $page['title'];
$pageTitle = stripos($title, 'Apex Business Consultancy') !== false ? $title : $title . ' | Apex Business Consultancy';
$description = $page['seo_description'] ?: 'Apex Business Consultancy information and guidance.';
$appUrl = rtrim((string)($config['app_url'] ?? ''), '/');
$canonical = !empty($page['canonical_url'])
    ? (string) $page['canonical_url']
    : ($appUrl !== '' && $slug !== '' ? $appUrl . '/page/' . rawurlencode($slug) : '');
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="<?= http_response_code() === 404 ? 'noindex,nofollow' : 'index,follow' ?>">
  <meta name="application-name" content="Apex Business Consultancy">
  <meta property="og:site_name" content="Apex Business Consultancy">
  <link rel="icon" type="image/svg+xml" href="/favicon.svg">
  <title><?= e($pageTitle) ?></title>
  <meta name="description" content="<?= e($description) ?>">
  <?php if ($canonical): ?><link rel="canonical" href="<?= e($canonical) ?>"><?php endif; ?>
  <script type="application/ld+json">
  {
    "@context": "https://schema.org",
    "@type": "WebSite",
    "name": "Apex Business Consultancy",
    "alternateName": ["Apex Business", "Apex Business Consultancy KSA"],
    "url": "https://apexbusinessksa.com/"
  }
  </script>
  <link rel="stylesheet" href="/styles.css">
  <link rel="stylesheet" href="/consulting.css">
  <link rel="stylesheet" href="/mobile-hardening.css">
</head>
<body>
<div class="site-shell">
  <header class="site-header">
    <a class="brand" href="/" aria-label="Apex Business Consultancy home"><span>apex</span></a>
    <nav><a href="/">Home</a><a href="/services">Services</a><a href="/insights">Insights</a><a href="/contact">Contact</a></nav>
  </header>
  <main class="consult-main">
    <article class="legal-page">
      <p class="kicker">APEX BUSINESS CONSULTANCY</p>
      <h1><?= e($page['title']) ?></h1>
      <div class="article-detail-body"><?= sanitize_rich_html((string)$page['content']) ?></div>
    </article>
  </main>
</div>
</body>
</html>
