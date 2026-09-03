<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';
$pdo = db($config);
$slug = trim((string) ($_GET['slug'] ?? ''));

$query = $pdo->prepare('
    SELECT p.*, 
           COALESCE(u.display_name, \'Apex Administrator\') AS author_name,
           COALESCE(p.image_url, m.public_url) AS featured_image,
           c.name AS category_name,
           c.slug AS category_slug
    FROM posts p 
    LEFT JOIN users u ON u.id = p.author_id 
    LEFT JOIN media m ON m.id = p.featured_media_id 
    LEFT JOIN post_categories pc ON pc.post_id = p.id
    LEFT JOIN categories c ON c.id = pc.category_id
    WHERE p.slug = ? AND p.status = \'published\' 
    LIMIT 1
');
$query->execute([$slug]);
$post = $query->fetch();

$is404 = false;
if (!$post) {
    http_response_code(404);
    $is404 = true;
    $post = [
        'id' => 0,
        'title' => 'Article Not Found',
        'description' => 'The requested article could not be found or has been moved.',
        'content' => '<p>The article you are looking for does not exist or has been relocated. Please browse our latest insights or return to the home page.</p>',
        'seo_title' => 'Article Not Found | Apex Business Consultancy',
        'seo_description' => 'The requested article could not be found.',
        'featured_image' => '',
        'category_name' => 'Insights',
        'author_name' => 'Apex Business Consultancy',
        'published_at' => date('Y-m-d H:i:s'),
        'tags' => '',
        'views' => 0
    ];
} else {
    // Increment view count
    $pdo->prepare('UPDATE posts SET views = views + 1 WHERE id = ?')->execute([(int) $post['id']]);
}

// Fetch related articles
$relatedPosts = [];
if (!$is404) {
    $relQuery = $pdo->prepare('
        SELECT p.title, p.slug, p.description, COALESCE(p.image_url, m.public_url) AS featured_image, p.published_at, c.name AS category_name
        FROM posts p
        LEFT JOIN media m ON m.id = p.featured_media_id
        LEFT JOIN post_categories pc ON pc.post_id = p.id
        LEFT JOIN categories c ON c.id = pc.category_id
        WHERE p.id != ? AND p.status = \'published\'
        ORDER BY p.published_at DESC
        LIMIT 3
    ');
    $relQuery->execute([(int) $post['id']]);
    $relatedPosts = $relQuery->fetchAll();
}

$title = !empty($post['seo_title']) ? $post['seo_title'] : $post['title'];
$pageTitle = stripos($title, 'Apex Business Consultancy') !== false ? $title : $title . ' | Apex Business Consultancy';
$description = !empty($post['seo_description']) ? $post['seo_description'] : $post['description'];
$author = $post['author_name'] ?? 'Apex Administrator';
$category = $post['category_name'] ?? 'Market Entry';
$image = $post['featured_image'] ?? '';
$dateFormatted = date('F j, Y', strtotime((string) $post['published_at']));
$readTime = max(1, (int) ceil(str_word_count(strip_tags((string) $post['content'])) / 200));
?><!doctype html>
<html lang="en">
  <head>
    <!-- Google tag (gtag.js) -->
    <script async src="https://www.googletagmanager.com/gtag/js?id=G-ZNWDH4V7BB"></script>
    <script>
      window.dataLayer = window.dataLayer || [];
      function gtag(){dataLayer.push(arguments);}
      gtag('js', new Date());

      gtag('config', 'G-ZNWDH4V7BB');
    </script>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <link rel="icon" type="image/svg+xml" href="/favicon.svg" />
    <title><?= e($pageTitle) ?></title>
    <meta name="description" content="<?= e($description) ?>" />
    <?php if (!empty($post['canonical_url'])): ?>
      <link rel="canonical" href="<?= e($post['canonical_url']) ?>" />
    <?php endif; ?>
    <?php if ($post['robots_index'] === 0 || $post['robots_follow'] === 0): ?>
      <meta name="robots" content="<?= $post['robots_index'] === 0 ? 'noindex' : 'index' ?>,<?= $post['robots_follow'] === 0 ? 'nofollow' : 'follow' ?>" />
    <?php endif; ?>
    <meta property="og:type" content="article" />
    <meta property="og:title" content="<?= e(!empty($post['og_title']) ? $post['og_title'] : $title) ?>" />
    <meta property="og:description" content="<?= e(!empty($post['og_description']) ? $post['og_description'] : $description) ?>" />
    <?php if (!empty($image)): ?>
      <meta property="og:image" content="<?= e($image) ?>" />
    <?php endif; ?>

    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&family=Playfair+Display:ital,wght@0,600;1,400&display=swap" rel="stylesheet" />
    <link rel="stylesheet" href="../styles.css" />
    <link rel="stylesheet" href="../consulting.css" />
    <link rel="stylesheet" href="../insights-fresh.css" />
    <style>
      .article-page-container { max-width: 920px; margin: 0 auto; padding: 40px 24px 80px; }
      .article-breadcrumb { display: flex; align-items: center; gap: 8px; font-size: 11px; font-weight: 800; color: #5c7882; text-transform: uppercase; letter-spacing: 0.8px; margin-bottom: 20px; }
      .article-breadcrumb a { color: inherit; text-decoration: none; }
      .article-breadcrumb a:hover { color: #58c748; }
      .article-header h1 { font-size: clamp(34px, 5vw, 56px); font-weight: 800; line-height: 1.05; letter-spacing: -2.5px; color: #07152f; margin: 0 0 20px; }
      .article-meta-bar { display: flex; align-items: center; gap: 14px; font-size: 13px; font-weight: 700; color: #5c7882; border-bottom: 1px solid rgba(121, 168, 180, 0.25); padding-bottom: 20px; margin-bottom: 32px; flex-wrap: wrap; }
      .article-hero-figure { margin: 0 0 40px; }
      .article-hero-figure img { width: 100%; max-height: 520px; object-fit: cover; border-radius: 20px; box-shadow: 0 16px 40px rgba(7, 21, 47, 0.08); }
      .article-hero-figure figcaption { margin-top: 10px; font-size: 12px; color: #667b82; font-style: italic; }
      .article-lead { font-size: 20px; font-weight: 600; line-height: 1.6; color: #3a4f59; margin-bottom: 36px; }
      .article-body { font-size: 16px; line-height: 1.85; color: #20313a; margin-bottom: 48px; }
      .article-body h2 { font-size: 28px; font-weight: 800; color: #07152f; letter-spacing: -1.2px; margin: 40px 0 16px; }
      .article-body h3 { font-size: 22px; font-weight: 800; color: #07152f; margin: 32px 0 12px; }
      .article-body p { margin-bottom: 24px; }
      .article-body blockquote { margin: 36px 0; padding: 24px 28px; background: #eaf9fb; border-left: 4px solid #58c748; border-radius: 0 14px 14px 0; font-size: 18px; font-weight: 700; color: #07152f; line-height: 1.5; font-style: italic; }
      .article-tags { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; padding: 20px 0; border-top: 1px solid rgba(121, 168, 180, 0.25); border-bottom: 1px solid rgba(121, 168, 180, 0.25); margin-bottom: 48px; }
      .article-tag-chip { padding: 6px 12px; border-radius: 8px; background: #e8f4f6; color: #07152f; font-size: 11px; font-weight: 700; }
      .author-card { display: grid; grid-template-columns: 60px 1fr; gap: 20px; align-items: center; padding: 28px; background: #f4fcfe; border: 1px solid rgba(121, 168, 180, 0.3); border-radius: 20px; margin-bottom: 60px; }
      .author-card img { width: 60px; height: 60px; border-radius: 50%; object-fit: cover; }
      .related-section { margin-top: 60px; }
      .related-section h2 { font-size: 28px; font-weight: 800; color: #07152f; margin-bottom: 24px; }
      .related-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 24px; }
      .related-card { display: block; text-decoration: none; background: #fff; border: 1px solid rgba(121, 168, 180, 0.25); border-radius: 16px; overflow: hidden; transition: all .2s ease; }
      .related-card:hover { transform: translateY(-3px); box-shadow: 0 14px 30px rgba(7, 21, 47, 0.08); border-color: #58c748; }
      .related-card img { width: 100%; height: 160px; object-fit: cover; }
      .related-card-body { padding: 18px; }
      .related-card-body strong { display: block; font-size: 15px; color: #07152f; font-weight: 800; margin-bottom: 8px; line-height: 1.35; }
      .related-card-body p { font-size: 12px; color: #667b82; line-height: 1.5; margin: 0; }
    </style>
  </head>
  <body style="margin:0; background:linear-gradient(135deg, #f3fcfd 0%, #e8f9fb 100%); font-family:Manrope, sans-serif; color:#07152f;">
    <header class="site-header container" style="display:flex; justify-content:space-between; align-items:center; padding:24px 32px; max-width:1200px; margin:0 auto;">
      <a class="brand" href="/" aria-label="Apex Business home" style="display:flex; align-items:center; gap:10px; text-decoration:none; font-weight:800; color:#07152f; font-size:20px;">
        <span class="brand-mark" aria-hidden="true" style="display:inline-grid; grid-template-columns:repeat(3, 1fr); width:20px; height:20px;">
          <i style="grid-area:1/2;background:#45c5dc;border-radius:6px 2px 6px 2px;"></i>
          <i style="grid-area:2/3;background:#527ce0;border-radius:6px 2px 6px 2px;"></i>
          <i style="grid-area:3/2;background:#67c746;border-radius:6px 2px 6px 2px;"></i>
          <i style="grid-area:2/1;background:#f1d15d;border-radius:6px 2px 6px 2px;"></i>
          <i style="grid-area:2/2;background:#fc9c54;border-radius:6px 2px 6px 2px;transform:scale(.7);"></i>
        </span>
        <span>apex</span>
      </a>
      <nav class="desktop-nav" style="display:flex; gap:24px; font-size:13px; font-weight:700;">
        <a href="/" style="color:inherit; text-decoration:none;">Home</a>
        <a href="/about" style="color:inherit; text-decoration:none;">About</a>
        <a href="/services" style="color:inherit; text-decoration:none;">Services</a>
        <a href="/insights" style="color:#58c748; text-decoration:none;">Insights</a>
        <a href="/contact" style="color:inherit; text-decoration:none;">Contact</a>
      </nav>
    </header>

    <main class="article-page-container">
      <nav class="article-breadcrumb" aria-label="Breadcrumb">
        <a href="/">Home</a>
        <span>›</span>
        <a href="/insights">Insights</a>
        <span>›</span>
        <span><?= e($category) ?></span>
      </nav>

      <header class="article-header">
        <span style="display:inline-block; padding:6px 14px; border-radius:999px; background:#e1f7fa; color:#206e7c; font-size:11px; font-weight:800; text-transform:uppercase; letter-spacing:0.8px; margin-bottom:16px;">
          <?= e($category) ?>
        </span>
        <h1><?= e($post['title']) ?></h1>
        <div class="article-meta-bar">
          <span>By <strong><?= e($author) ?></strong></span>
          <span>·</span>
          <span><?= e($dateFormatted) ?></span>
          <span>·</span>
          <span><?= $readTime ?> min read</span>
          <?php if (!empty($post['views'])): ?>
            <span>·</span>
            <span><?= number_format((int) $post['views']) ?> views</span>
          <?php endif; ?>
        </div>
      </header>

      <?php if (!empty($image)): ?>
        <figure class="article-hero-figure">
          <img src="<?= e($image) ?>" alt="<?= e(!empty($post['image_alt']) ? $post['image_alt'] : $post['title']) ?>" />
          <?php if (!empty($post['image_caption'])): ?>
            <figcaption><?= e($post['image_caption']) ?></figcaption>
          <?php endif; ?>
        </figure>
      <?php endif; ?>

      <?php if (!empty($post['description'])): ?>
        <p class="article-lead"><?= e($post['description']) ?></p>
      <?php endif; ?>

      <div class="article-body">
        <?= $post['content'] ?>
      </div>

      <?php if (!empty($post['tags'])): ?>
        <div class="article-tags">
          <strong style="font-size:11px; color:#5c7882; text-transform:uppercase;">Tags:</strong>
          <?php foreach (explode(',', (string) $post['tags']) as $tag): ?>
            <span class="article-tag-chip"><?= e(trim($tag)) ?></span>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <aside class="author-card">
        <img src="https://images.unsplash.com/photo-1494790108377-be9c29b29330?auto=format&fit=crop&w=120&q=80" alt="<?= e($author) ?>" />
        <div>
          <strong style="display:block; font-size:16px; color:#07152f; margin-bottom:4px;"><?= e($author) ?></strong>
          <span style="display:block; font-size:13px; color:#667b82;">Strategic Advisory &amp; Market Insights · Apex Business</span>
        </div>
      </aside>

      <?php if (!empty($relatedPosts)): ?>
        <section class="related-section">
          <h2>Related Insights</h2>
          <div class="related-grid">
            <?php foreach ($relatedPosts as $rel): ?>
              <a class="related-card" href="/insights/<?= urlencode($rel['slug']) ?>">
                <?php if (!empty($rel['featured_image'])): ?>
                  <img src="<?= e($rel['featured_image']) ?>" alt="<?= e($rel['title']) ?>" />
                <?php endif; ?>
                <div class="related-card-body">
                  <span style="font-size:10px; font-weight:800; color:#58c748; text-transform:uppercase; display:block; margin-bottom:4px;"><?= e($rel['category_name'] ?? 'Insights') ?></span>
                  <strong><?= e($rel['title']) ?></strong>
                  <p><?= e(mb_strimwidth((string) $rel['description'], 0, 95, '...')) ?></p>
                </div>
              </a>
            <?php endforeach; ?>
          </div>
        </section>
      <?php endif; ?>

      <div style="margin-top:40px; text-align:center;">
        <a href="/insights" class="button" style="display:inline-flex; align-items:center; gap:8px; padding:14px 28px; border-radius:999px; background:#58c748; color:#fff; text-decoration:none; font-weight:800; font-size:13px;">
          ← Back to all Insights
        </a>
      </div>
    </main>
  </body>
</html>
