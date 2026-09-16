<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/app/bootstrap.php';
start_secure_session($config);

try {
    $pdo = db($config);
} catch (Throwable $e) {
    error_log('Apex logs database unavailable: ' . $e->getMessage());
    json_response(['error' => 'The CMS database is temporarily unavailable.'], 503);
}
$user = require_permission($pdo, 'logs.view');

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $search = trim((string) ($_GET['search'] ?? ''));
    $module = trim((string) ($_GET['module'] ?? ''));
    $status = trim((string) ($_GET['status'] ?? ''));
    $timeframe = trim((string) ($_GET['timeframe'] ?? ''));
    $page = max(1, (int) ($_GET['page'] ?? 1));
    $limit = min(100, max(1, (int) ($_GET['limit'] ?? 25)));
    $offset = ($page - 1) * $limit;

    $where = [];
    $params = [];

    if ($search !== '') {
        $where[] = '(a.action LIKE ? OR a.entity_type LIKE ? OR u.display_name LIKE ? OR a.metadata LIKE ?)';
        $searchWild = '%' . $search . '%';
        $params[] = $searchWild;
        $params[] = $searchWild;
        $params[] = $searchWild;
        $params[] = $searchWild;
    }

    if ($module !== '' && $module !== 'all') {
        $where[] = 'a.entity_type = ?';
        $params[] = $module;
    }

    if ($status !== '' && $status !== 'all') {
        $where[] = 'a.status = ?';
        $params[] = $status;
    }

    if ($timeframe === 'today') {
        $where[] = 'a.created_at >= CURDATE()';
    } elseif ($timeframe === 'week') {
        $where[] = 'a.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)';
    } elseif ($timeframe === 'month') {
        $where[] = 'a.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)';
    }

    $whereSql = !empty($where) ? ' WHERE ' . implode(' AND ', $where) : '';

    $countQuery = $pdo->prepare('SELECT COUNT(*) FROM activity_logs a LEFT JOIN users u ON u.id = a.user_id' . $whereSql);
    $countQuery->execute($params);
    $total = (int) $countQuery->fetchColumn();

    $sql = 'SELECT a.id, a.user_id, a.action, a.entity_type, a.entity_id, a.status, a.metadata,
            a.ip_address, a.created_at,
            COALESCE(u.display_name, \'Apex Administrator\') AS actor,
            u.email AS actor_email
            FROM activity_logs a 
            LEFT JOIN users u ON u.id = a.user_id' 
            . $whereSql 
            . ' ORDER BY a.created_at DESC LIMIT ' . $limit . ' OFFSET ' . $offset;

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $logs = $stmt->fetchAll();
    foreach ($logs as &$log) {
        $log['ip'] = binary_ip_to_string($log['ip_address'] ?? null);
        unset($log['ip_address']);
    }
    unset($log);

    json_response([
        'ok' => true,
        'data' => $logs,
        'pagination' => [
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'pages' => max(1, (int) ceil($total / $limit))
        ]
    ]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $payload = request_json();
    $action = trim((string) ($payload['action'] ?? ''));
    if ($action === '') {
        json_response(['error' => 'Action description is required.'], 422);
    }

    $entityType = $payload['entity_type'] ?? ($payload['module'] ?? 'system');
    $entityId = isset($payload['entity_id']) ? (int) $payload['entity_id'] : null;
    $status = in_array($payload['status'] ?? '', ['success', 'failure'], true) ? $payload['status'] : 'success';
    $metadata = $payload['metadata'] ?? [];
    $userId = (int) $user['id'];
    $ip = ip_to_binary(request_ip_address());

    $stmt = $pdo->prepare('INSERT INTO activity_logs (user_id, action, entity_type, entity_id, status, metadata, ip_address, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())');
    $stmt->execute([$userId, $action, $entityType, $entityId, $status, json_encode($metadata), $ip]);
    $id = (int) $pdo->lastInsertId();

    json_response(['ok' => true, 'id' => $id], 201);
}

json_response(['error' => 'Method not allowed.'], 405);
