<?php
declare(strict_types=1);

http_response_code(404);
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
$basePath = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
$basePath = preg_replace('#/public$#', '', $basePath) ?: '';
?><!doctype html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex,follow">
  <title>Page Not Found | Apex Business Consultancy</title>
  <link rel="icon" type="image/svg+xml" href="<?= htmlspecialchars($basePath . '/favicon.svg', ENT_QUOTES, 'UTF-8') ?>">
  <link rel="stylesheet" href="<?= htmlspecialchars($basePath . '/styles.css', ENT_QUOTES, 'UTF-8') ?>">
  <link rel="stylesheet" href="<?= htmlspecialchars($basePath . '/pages.css', ENT_QUOTES, 'UTF-8') ?>">
</head>
<body>
  <main class="content-page not-found-page">
    <section class="not-found-content">
      <p class="eyebrow">PAGE NOT FOUND</p>
      <h1>That page is not available.</h1>
      <p>The address may be incorrect or the page may have moved. Use the navigation below to continue.</p>
      <div class="not-found-actions">
        <a class="button" href="/">Return home <span aria-hidden="true">&rarr;</span></a>
        <a class="button-ghost" href="/contact">Contact Apex</a>
      </div>
    </section>
  </main>
</body>
</html>
