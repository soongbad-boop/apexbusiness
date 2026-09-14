<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

$config = ['app_url' => 'https://example.com/demo'];

$pagePath = page_public_path('privacy-policy');
if ($pagePath !== '/page/privacy-policy') {
    fwrite(STDERR, "Page public path assertion failed.\n");
    exit(1);
}

$defaultCanonical = page_canonical_url($config, ['title' => 'Example'], 'privacy-policy');
if ($defaultCanonical !== 'https://example.com/demo/page/privacy-policy') {
    fwrite(STDERR, "Default page canonical URL assertion failed.\n");
    exit(1);
}

$customCanonical = page_canonical_url($config, ['canonical_url' => 'https://cdn.example.com/custom-page'], 'privacy-policy');
if ($customCanonical !== 'https://cdn.example.com/custom-page') {
    fwrite(STDERR, "Custom page canonical URL assertion failed.\n");
    exit(1);
}

$absoluteImage = absolute_url_for_path($config, '/storage/media/example.jpg');
if ($absoluteImage !== 'https://example.com/demo/storage/media/example.jpg') {
    fwrite(STDERR, "Media URL normalization assertion failed.\n");
    exit(1);
}

$relativeImage = absolute_url_for_path($config, 'storage/media/example.jpg');
if ($relativeImage !== 'https://example.com/demo/storage/media/example.jpg') {
    fwrite(STDERR, "Relative media URL normalization assertion failed.\n");
    exit(1);
}

$blogImage = blog_featured_image_url($config, ['featured_image' => 'storage/media/example.jpg']);
if ($blogImage !== 'https://example.com/demo/storage/media/example.jpg') {
    fwrite(STDERR, "Blog featured image normalization assertion failed.\n");
    exit(1);
}

$blogAbsoluteImage = blog_featured_image_url($config, ['featured_image' => 'https://images.example.com/example.jpg']);
if ($blogAbsoluteImage !== 'https://images.example.com/example.jpg') {
    fwrite(STDERR, "Absolute blog featured image passthrough assertion failed.\n");
    exit(1);
}

$blogMissingImage = blog_featured_image_url($config, []);
if ($blogMissingImage !== '') {
    fwrite(STDERR, "Missing blog featured image assertion failed.\n");
    exit(1);
}

$externalImage = absolute_url_for_path($config, 'https://images.example.com/example.jpg');
if ($externalImage !== 'https://images.example.com/example.jpg') {
    fwrite(STDERR, "External media URL passthrough assertion failed.\n");
    exit(1);
}

echo "URL helper assertions passed.\n";
