<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/app/bootstrap.php';
$pdo = db($config);

function post_slug(string $title, string $slug): string
{
    $slug = strtolower(trim($slug));
    if ($slug === '') {
        $slug = strtolower((string) preg_replace('/[^A-Za-z0-9]+/', '-', $title));
    }
    $slug = trim((string) preg_replace('/-+/', '-', $slug), '-');
    if ($slug === '' || !preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug)) {
        json_response(['error' => 'Use a valid URL slug containing letters, numbers, and hyphens only.'], 422);
    }
    return mb_substr($slug, 0, 255);
}

function post_datetime(mixed $value, bool $required = false): ?string
{
    $raw = trim((string) $value);
    if ($raw === '') {
        if ($required) {
            json_response(['error' => 'A publication date is required for scheduled posts.'], 422);
        }
        return null;
    }
    try {
        $date = new DateTimeImmutable($raw);
        return $date->format('Y-m-d H:i:s');
    } catch (Throwable) {
        json_response(['error' => 'Publication date is invalid.'], 422);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $user = current_user();
    $isEditor = $user !== null && user_has_permission($pdo, $user, 'posts.manage');
    $requestedStatus = normalized_text($_GET['status'] ?? '', 30);
    $limit = min(100, max(1, (int) ($_GET['limit'] ?? 50)));
    $offset = max(0, (int) ($_GET['offset'] ?? 0));

    $sql = 'SELECT p.*, u.display_name AS author_name, COALESCE(p.image_url, m.public_url) AS featured_image,
                   cat.category_name, cat.category_slug
            FROM posts p
            LEFT JOIN users u ON u.id = p.author_id
            LEFT JOIN media m ON m.id = p.featured_media_id
            LEFT JOIN (
                SELECT pc.post_id, MIN(c.name) AS category_name, MIN(c.slug) AS category_slug
                FROM post_categories pc
                INNER JOIN categories c ON c.id = pc.category_id
                GROUP BY pc.post_id
            ) cat ON cat.post_id = p.id';

    $where = [];
    $params = [];

    if ($requestedStatus !== '') {
        if (!$isEditor) {
            json_response(['error' => 'Authentication required for non-public post status filters.'], 401);
        }
        if (!in_array($requestedStatus, ['draft', 'published', 'scheduled', 'archived'], true)) {
            json_response(['error' => 'Invalid post status filter.'], 422);
        }
        $where[] = 'p.status = ?';
        $params[] = $requestedStatus;
    } elseif (!$isEditor || (string) ($_GET['scope'] ?? '') !== 'admin') {
        // Public API must never expose drafts, archived posts, or future-dated content.
        $where[] = "p.status = 'published'";
        $where[] = '(p.published_at IS NULL OR p.published_at <= NOW())';
    }

    $slug = normalized_text($_GET['slug'] ?? '', 255);
    if ($slug !== '') {
        $where[] = 'p.slug = ?';
        $params[] = $slug;
    }

    if ($where) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= ' ORDER BY p.is_featured DESC, COALESCE(p.published_at, p.created_at) DESC LIMIT ' . $limit . ' OFFSET ' . $offset;

    $query = $pdo->prepare($sql);
    $query->execute($params);
    json_response(['data' => $query->fetchAll()]);
}

if (!in_array($_SERVER['REQUEST_METHOD'], ['POST', 'PATCH', 'DELETE'], true)) {
    json_response(['error' => 'Method not allowed.'], 405);
}

require_csrf();
$user = require_permission($pdo, 'posts.manage');

if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    $id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
    if (!$id) {
        json_response(['error' => 'A valid post id is required.'], 422);
    }
    $query = $pdo->prepare('DELETE FROM posts WHERE id = ?');
    $query->execute([$id]);
    if ($query->rowCount() === 0) {
        json_response(['error' => 'Post not found.'], 404);
    }
    log_activity($pdo, (int) $user['id'], 'Post deleted', 'post', (int) $id);
    json_response(['ok' => true]);
}

$payload = request_json();
$title = normalized_text($payload['title'] ?? '', 255);
$slug = post_slug($title, normalized_text($payload['slug'] ?? '', 255));
$status = normalized_text($payload['status'] ?? 'draft', 30);
if ($title === '' || !in_array($status, ['draft', 'published', 'scheduled', 'archived'], true)) {
    json_response(['error' => 'A valid post title and status are required.'], 422);
}

$content = sanitize_rich_html((string) ($payload['content'] ?? ''));
if (in_array($status, ['published', 'scheduled'], true) && trim(strip_tags($content)) === '') {
    json_response(['error' => 'Published and scheduled posts require article content.'], 422);
}

$description = normalized_text($payload['excerpt'] ?? ($payload['description'] ?? ''), 2000);
$imageUrl = valid_http_url_or_path($payload['image'] ?? ($payload['image_url'] ?? null));
$imageAlt = normalized_text($payload['image_alt'] ?? '', 255);
$imageCaption = normalized_text($payload['image_caption'] ?? '', 500);
$tags = normalized_text($payload['tags'] ?? '', 500);
$isFeatured = !empty($payload['is_featured']) ? 1 : 0;
$allowComments = isset($payload['allow_comments']) ? (!empty($payload['allow_comments']) ? 1 : 0) : 1;
$publishedInput = $payload['published_at'] ?? ($payload['publishedAt'] ?? null);
$publishedAt = post_datetime($publishedInput, $status === 'scheduled');
if ($status === 'published' && $publishedAt === null) {
    $publishedAt = date('Y-m-d H:i:s');
}
if (in_array($status, ['draft', 'archived'], true) && trim((string) $publishedInput) === '') {
    $publishedAt = null;
}

$seoTitle = normalized_text($payload['seo_title'] ?? '', 255);
$seoDescription = normalized_text($payload['seo_description'] ?? '', 320);
$focusKeyword = normalized_text($payload['focus_keyword'] ?? '', 120);
$canonicalUrl = valid_http_url_or_path($payload['canonical_url'] ?? null);
$ogTitle = normalized_text($payload['og_title'] ?? '', 255);
$ogDescription = normalized_text($payload['og_description'] ?? '', 320);
$robotsIndex = isset($payload['robots_index']) ? (!empty($payload['robots_index']) ? 1 : 0) : 1;
$robotsFollow = isset($payload['robots_follow']) ? (!empty($payload['robots_follow']) ? 1 : 0) : 1;
$categoryName = normalized_text($payload['category'] ?? '', 160);

try {
    $pdo->beginTransaction();

    if ($_SERVER['REQUEST_METHOD'] === 'PATCH') {
        $id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
        if (!$id) {
            json_response(['error' => 'A valid post id is required.'], 422);
        }
        $query = $pdo->prepare('UPDATE posts SET title = ?, slug = ?, description = ?, content = ?, status = ?, image_url = ?, image_alt = ?, image_caption = ?, tags = ?, is_featured = ?, allow_comments = ?, published_at = ?, seo_title = ?, seo_description = ?, focus_keyword = ?, canonical_url = ?, og_title = ?, og_description = ?, robots_index = ?, robots_follow = ? WHERE id = ?');
        $query->execute([$title, $slug, $description ?: null, $content, $status, $imageUrl, $imageAlt ?: null, $imageCaption ?: null, $tags ?: null, $isFeatured, $allowComments, $publishedAt, $seoTitle ?: null, $seoDescription ?: null, $focusKeyword ?: null, $canonicalUrl, $ogTitle ?: null, $ogDescription ?: null, $robotsIndex, $robotsFollow, $id]);
        if ($query->rowCount() === 0) {
            $exists = $pdo->prepare('SELECT 1 FROM posts WHERE id = ?');
            $exists->execute([$id]);
            if (!$exists->fetchColumn()) {
                $pdo->rollBack();
                json_response(['error' => 'Post not found.'], 404);
            }
        }
    } else {
        $query = $pdo->prepare('INSERT INTO posts (title, slug, description, content, status, image_url, image_alt, image_caption, tags, is_featured, allow_comments, published_at, seo_title, seo_description, focus_keyword, canonical_url, og_title, og_description, robots_index, robots_follow, author_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $query->execute([$title, $slug, $description ?: null, $content, $status, $imageUrl, $imageAlt ?: null, $imageCaption ?: null, $tags ?: null, $isFeatured, $allowComments, $publishedAt, $seoTitle ?: null, $seoDescription ?: null, $focusKeyword ?: null, $canonicalUrl, $ogTitle ?: null, $ogDescription ?: null, $robotsIndex, $robotsFollow, (int) $user['id']]);
        $id = (int) $pdo->lastInsertId();
    }

    if ($categoryName !== '') {
        $categorySlug = post_slug($categoryName, '');
        $catQuery = $pdo->prepare('SELECT id FROM categories WHERE name = ? OR slug = ? LIMIT 1');
        $catQuery->execute([$categoryName, $categorySlug]);
        $catId = $catQuery->fetchColumn();
        if (!$catId) {
            $catInsert = $pdo->prepare('INSERT INTO categories (name, slug) VALUES (?, ?)');
            $catInsert->execute([$categoryName, $categorySlug]);
            $catId = (int) $pdo->lastInsertId();
        }
        if ($catId) {
            $pdo->prepare('DELETE FROM post_categories WHERE post_id = ?')->execute([$id]);
            $pdo->prepare('INSERT INTO post_categories (post_id, category_id) VALUES (?, ?)')->execute([$id, $catId]);
        }
    }

    $pdo->commit();
    log_activity($pdo, (int) $user['id'], ($_SERVER['REQUEST_METHOD'] === 'PATCH' ? 'Updated article: ' : 'Created article: ') . $title, 'post', (int) $id);
    json_response(['ok' => true, 'id' => (int) $id, 'slug' => $slug], $_SERVER['REQUEST_METHOD'] === 'POST' ? 201 : 200);
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if ((string) $e->getCode() === '23000') {
        json_response(['error' => 'That article URL slug is already in use.'], 409);
    }
    json_response(['error' => 'Unable to save the article.'], 500);
}
