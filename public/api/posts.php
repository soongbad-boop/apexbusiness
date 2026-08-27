<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/app/bootstrap.php';
$pdo = db($config);

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $status = $_GET['status'] ?? null;
    $sql = 'SELECT p.*, u.display_name AS author_name, COALESCE(p.image_url, m.public_url) AS featured_image, c.name AS category_name, c.slug AS category_slug 
            FROM posts p 
            LEFT JOIN users u ON u.id = p.author_id 
            LEFT JOIN media m ON m.id = p.featured_media_id
            LEFT JOIN post_categories pc ON pc.post_id = p.id
            LEFT JOIN categories c ON c.id = pc.category_id';
    $params = [];
    if ($status && in_array($status, ['draft', 'published', 'scheduled', 'archived'], true)) {
        $sql .= ' WHERE p.status = ?';
        $params[] = $status;
    }
    $sql .= ' GROUP BY p.id ORDER BY p.is_featured DESC, COALESCE(p.published_at, p.created_at) DESC';
    $query = $pdo->prepare($sql);
    $query->execute($params);
    $posts = $query->fetchAll();

    json_response(['data' => $posts]);
}

if (!in_array($_SERVER['REQUEST_METHOD'], ['POST', 'PATCH', 'DELETE'], true)) {
    json_response(['error' => 'Method not allowed.'], 405);
}

// User context (session user or default admin)
$user = current_user() ?? ['id' => 1, 'role_id' => 1, 'display_name' => 'Admin'];

if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    $id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
    if (!$id) {
        json_response(['error' => 'A valid post id is required.'], 422);
    }
    $query = $pdo->prepare('DELETE FROM posts WHERE id = ?');
    $query->execute([$id]);
    log_activity($pdo, (int) $user['id'], 'Post deleted', 'post', $id);
    json_response(['ok' => true]);
}

$payload = request_json();
$title = trim((string) ($payload['title'] ?? ''));
$slug = trim((string) ($payload['slug'] ?? ''));
if ($slug === '' && $title !== '') {
    $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $title), '-'));
}
$content = (string) ($payload['content'] ?? '');
$status = (string) ($payload['status'] ?? 'draft');
if ($title === '' || !in_array($status, ['draft', 'published', 'scheduled', 'archived'], true)) {
    json_response(['error' => 'Invalid post title or status.'], 422);
}

$description = $payload['excerpt'] ?? ($payload['description'] ?? null);
$imageUrl = $payload['image'] ?? ($payload['image_url'] ?? null);
$imageAlt = $payload['image_alt'] ?? null;
$imageCaption = $payload['image_caption'] ?? null;
$tags = $payload['tags'] ?? null;
$isFeatured = !empty($payload['is_featured']) ? 1 : 0;
$allowComments = isset($payload['allow_comments']) ? ($payload['allow_comments'] ? 1 : 0) : 1;
$publishedAt = !empty($payload['published_at']) ? $payload['published_at'] : ($status === 'published' ? date('Y-m-d H:i:s') : null);
$seoTitle = $payload['seo_title'] ?? null;
$seoDescription = $payload['seo_description'] ?? null;
$focusKeyword = $payload['focus_keyword'] ?? null;
$canonicalUrl = $payload['canonical_url'] ?? null;
$ogTitle = $payload['og_title'] ?? null;
$ogDescription = $payload['og_description'] ?? null;
$robotsIndex = isset($payload['robots_index']) ? ($payload['robots_index'] ? 1 : 0) : 1;
$robotsFollow = isset($payload['robots_follow']) ? ($payload['robots_follow'] ? 1 : 0) : 1;
$categoryName = $payload['category'] ?? null;

if ($_SERVER['REQUEST_METHOD'] === 'PATCH') {
    $id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
    if (!$id) {
        json_response(['error' => 'A valid post id is required.'], 422);
    }
    $query = $pdo->prepare('UPDATE posts SET title = ?, slug = ?, description = ?, content = ?, status = ?, image_url = ?, image_alt = ?, image_caption = ?, tags = ?, is_featured = ?, allow_comments = ?, published_at = ?, seo_title = ?, seo_description = ?, focus_keyword = ?, canonical_url = ?, og_title = ?, og_description = ?, robots_index = ?, robots_follow = ? WHERE id = ?');
    $query->execute([$title, $slug, $description, $content, $status, $imageUrl, $imageAlt, $imageCaption, $tags, $isFeatured, $allowComments, $publishedAt, $seoTitle, $seoDescription, $focusKeyword, $canonicalUrl, $ogTitle, $ogDescription, $robotsIndex, $robotsFollow, $id]);

    if ($categoryName) {
        $catQuery = $pdo->prepare('SELECT id FROM categories WHERE name = ? OR slug = ? LIMIT 1');
        $catQuery->execute([$categoryName, $categoryName]);
        $catId = $catQuery->fetchColumn();
        if ($catId) {
            $pdo->prepare('DELETE FROM post_categories WHERE post_id = ?')->execute([$id]);
            $pdo->prepare('INSERT INTO post_categories (post_id, category_id) VALUES (?, ?)')->execute([$id, $catId]);
        }
    }

    log_activity($pdo, (int) $user['id'], "Updated article: {$title}", 'post', $id);
    json_response(['ok' => true, 'id' => $id, 'slug' => $slug]);
}

$query = $pdo->prepare('INSERT INTO posts (title, slug, description, content, status, image_url, image_alt, image_caption, tags, is_featured, allow_comments, published_at, seo_title, seo_description, focus_keyword, canonical_url, og_title, og_description, robots_index, robots_follow, author_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
$query->execute([$title, $slug, $description, $content, $status, $imageUrl, $imageAlt, $imageCaption, $tags, $isFeatured, $allowComments, $publishedAt, $seoTitle, $seoDescription, $focusKeyword, $canonicalUrl, $ogTitle, $ogDescription, $robotsIndex, $robotsFollow, $user['id']]);
$id = (int) $pdo->lastInsertId();

if ($categoryName) {
    $catQuery = $pdo->prepare('SELECT id FROM categories WHERE name = ? OR slug = ? LIMIT 1');
    $catQuery->execute([$categoryName, $categoryName]);
    $catId = $catQuery->fetchColumn();
    if ($catId) {
        $pdo->prepare('INSERT INTO post_categories (post_id, category_id) VALUES (?, ?)')->execute([$id, $catId]);
    }
}

log_activity($pdo, (int) $user['id'], "Created article: {$title}", 'post', $id);
json_response(['ok' => true, 'id' => $id, 'slug' => $slug], 201);
