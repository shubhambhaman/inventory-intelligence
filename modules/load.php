<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/session.php';

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/error_handler.php';
require_once __DIR__ . '/../config/modules.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    header('Allow: GET');
    api_error_response(405, 'METHOD_NOT_ALLOWED', 'Only GET requests are allowed.');
}

if (
    empty($_SESSION['authenticated']) ||
    empty($_SESSION['user_id']) ||
    empty($_SESSION['tenant_id'])
) {
    api_unauthenticated();
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/authorization.php';

$module = app_module_definition($_GET['module'] ?? 'dashboard');

if ($module === null) {
    api_not_found('Module not found.');
}

if (!empty($module['permission'])) {
    requirePermission((string) $module['permission']);
}

if (!is_file($module['view'])) {
    api_not_found('Module view not found.');
}

$basePath = app_base_path();
$e = static fn(mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$moduleName = (string) $module['name'];
$moduleTitle = (string) $module['title'];
$moduleApiBase = $basePath . '/api/v1/' . $moduleName;
$firstName = 'User';

if ($moduleName === 'dashboard') {
    try {
        $stmt = $pdo->prepare(
            'SELECT name
             FROM users
             WHERE id = :user_id
               AND tenant_id = :tenant_id
               AND status = :status
             LIMIT 1'
        );
        $stmt->execute([
            ':user_id' => (int) $_SESSION['user_id'],
            ':tenant_id' => (int) $_SESSION['tenant_id'],
            ':status' => 'active',
        ]);
        $name = trim((string) $stmt->fetchColumn());
        $parts = preg_split('/\s+/', $name, -1, PREG_SPLIT_NO_EMPTY);
        if ($parts) {
            $firstName = $parts[0];
        }
    } catch (Throwable $throwable) {
        error_log('Dashboard module context error: ' . $throwable->getMessage());
    }
}

if (!empty($module['data']) && is_file($module['data'])) {
    require $module['data'];
}

ob_start();
require $module['view'];
$html = ob_get_clean();

api_success_response([
    'module' => $moduleName,
    'title' => $moduleTitle,
    'html' => $html,
]);
