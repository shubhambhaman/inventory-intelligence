<?php
declare(strict_types=1);

/**
 * Inventory Intelligence dashboard shell.
 *
 * The shell owns authentication, shared assets, sidebar, top navbar, and the
 * allowlisted module mount point. Module views are rendered inside the main
 * content area only.
 */

require_once __DIR__ . '/config/session.php';
require_once __DIR__ . '/config/csrf.php';

if (
    empty($_SESSION['authenticated']) ||
    empty($_SESSION['user_id']) ||
    empty($_SESSION['tenant_id'])
) {
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/authorization.php';
require_once __DIR__ . '/config/modules.php';

$csrfToken = csrf_token();
$e = static fn(mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');


$basePath = '';

$asset = static function (string $path): string {
    $path = ltrim($path, '/');
    $file = __DIR__ . '/' . $path;

    return '/' . $path . '?v=' . (is_file($file) ? filemtime($file) : '1');
};


try {
    $contextStmt = $pdo->prepare(
        'SELECT
            u.name AS user_name,
            u.email AS user_email,
            u.role AS user_role,
            t.name AS company_name,
            t.country AS company_country
         FROM users u
         INNER JOIN tenants t ON t.id = u.tenant_id
         WHERE u.id = :user_id
           AND u.tenant_id = :tenant_id
           AND u.status = :user_status
           AND t.status = :tenant_status
         LIMIT 1'
    );

    $contextStmt->execute([
        ':user_id' => (int) $_SESSION['user_id'],
        ':tenant_id' => (int) $_SESSION['tenant_id'],
        ':user_status' => 'active',
        ':tenant_status' => 'active',
    ]);

    $context = $contextStmt->fetch();

    if (!$context) {
        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();

            setcookie(
                session_name(),
                '',
                [
                    'expires' => time() - 42000,
                    'path' => $params['path'],
                    'domain' => $params['domain'],
                    'secure' => $params['secure'],
                    'httponly' => $params['httponly'],
                    'samesite' => $params['samesite'] ?? 'Lax',
                ]
            );
        }

        session_destroy();
        header('Location: login.php');
        exit;
    }

    $userName = trim((string) $context['user_name']);
    $userEmail = trim((string) $context['user_email']);
    $userRole = trim((string) $context['user_role']);
    $companyName = trim((string) $context['company_name']);
    $companyLocation = trim((string) $context['company_country']);
} catch (Throwable $throwable) {
    error_log('Dashboard context error: ' . $throwable->getMessage());
    http_response_code(500);
    exit('Unable to load dashboard context.');
}

$nameParts = preg_split('/\s+/', $userName, -1, PREG_SPLIT_NO_EMPTY) ?: ['User'];
$firstName = $nameParts[0];
$initials = mb_strtoupper(
    mb_substr($nameParts[0], 0, 1) .
    (isset($nameParts[1]) ? mb_substr($nameParts[1], 0, 1) : '')
);

require __DIR__ . '/modules/dashboard/data.php';

$selectedModule = app_module_definition($_GET['module'] ?? 'dashboard') ?? app_module_definition('dashboard');

if ($selectedModule === null) {
    http_response_code(500);
    exit('Module registry is not configured.');
}

if (!empty($selectedModule['permission']) && !hasPermission((string) $selectedModule['permission'])) {
    $selectedModule = app_module_definition('dashboard');
}

if ($selectedModule === null || !is_file($selectedModule['view'])) {
    http_response_code(500);
    exit('Unable to load the requested module.');
}

$moduleName = (string) $selectedModule['name'];
$moduleTitle = (string) $selectedModule['title'];
$moduleApiBase = $basePath . '/api/v1/' . $moduleName;

if (!empty($selectedModule['data']) && is_file($selectedModule['data']) && $selectedModule['data'] !== __DIR__ . '/modules/dashboard/data.php') {
    require $selectedModule['data'];
}

ob_start();
require $selectedModule['view'];
$initialModuleHtml = ob_get_clean();

$logoutUrl = $basePath . '/api/v1/auth/logout.php';
$loginUrl = $basePath . '/login.php';
$moduleEndpoint = $basePath . '/modules/load.php';

$clientConfig = [
    'endpoints' => [
        'logout' => $logoutUrl,
        'login' => $loginUrl,
    ],
    'moduleEndpoint' => $moduleEndpoint,
    'initialModule' => $moduleName,
    'modules' => app_public_module_config($asset),
    'health' => $health,
    'trend' => $trend,
    'notificationCount' => count($notifications),
];

$clientJson = json_encode(
    $clientConfig,
    JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
);

$nav = [
    [
        'type' => 'link',
        'label' => 'Dashboard',
        'icon' => 'bi-grid-1x2-fill',
        'href' => $basePath . '/dashboard.php',
        'module' => 'dashboard',
    ],
    [
        'type' => 'group',
        'label' => 'Inventory',
        'icon' => 'bi-box-seam',
        'open' => true,
        'items' => [
            ['Stock Overview', 'bi-bar-chart-steps'],
            ['Products & SKUs', 'bi-tags', 'products'],
            ['Categories', 'bi-folder2-open', 'categories'],
            ['Warehouses', 'bi-buildings', 'warehouses'],
            ['Units', 'bi-rulers', 'units'],
            ['Stock Ledger', 'bi-journal-text'],
            ['Purchases', 'bi-box-arrow-in-down'],
            ['Sales / Outgoing', 'bi-box-arrow-up'],
            ['Transfers', 'bi-arrow-left-right'],
            ['Returns', 'bi-arrow-return-left'],
            ['Adjustments', 'bi-sliders'],
            ['Stock Reconciliation', 'bi-clipboard2-check'],
            ['Inventory Reports', 'bi-file-earmark-bar-graph'],
        ],

    ],
    [
        'type' => 'group',
        'label' => 'Intelligence',
        'icon' => 'bi-cpu',
        'open' => true,
        'items' => [
            ['Inventory Health', 'bi-heart-pulse'],
            ['Stockout Risk', 'bi-exclamation-triangle'],
            ['Excess / Dead Stock', 'bi-layers'],
            ['Replenishment', 'bi-arrow-repeat'],
            ['Decision Inbox', 'bi-inbox'],
            ['AI Assistant', 'bi-stars'],
        ],
    ],
    [
        'type' => 'group',
        'label' => 'Data Management',
        'icon' => 'bi-database',
        'open' => true,
        'items' => [
            ['Customers', 'bi-people', 'customers'],
            ['Suppliers', 'bi-truck', 'suppliers'],
            ['Imports', 'bi-cloud-arrow-up'],
            ['Data Quality', 'bi-patch-check'],
        ],
    ],
    [
        'type' => 'group',
        'label' => 'Settings',
        'icon' => 'bi-gear',
        'open' => false,
        'items' => [
            ['Company Settings', 'bi-building-gear'],
            ['Users & Roles', 'bi-person-gear'],
            ['Permissions', 'bi-shield-lock'],
            ['Notifications', 'bi-bell'],
        ],
    ],
];

$comingSoon = [
    ['Purchasing', 'bi-cart3'],
    ['Sales & Orders', 'bi-receipt'],
    ['Collections', 'bi-cash-coin'],
    ['Accounting & Finance', 'bi-calculator'],
    ['Manufacturing', 'bi-gear-wide-connected'],
    ['Logistics / Dispatch', 'bi-truck'],
    ['Business Intelligence', 'bi-bar-chart-line'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= $e($csrfToken) ?>">
    <title><?= $moduleName === 'dashboard' ? 'Inventory Platform' : $e($moduleTitle . ' Â· Inventory Platform') ?></title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="<?= $e($asset('assets/css/dashboard.css')) ?>" rel="stylesheet">
    <link href="<?= $e($asset('assets/css/app.css')) ?>" rel="stylesheet">
    <?php foreach ($selectedModule['css'] as $moduleCss): ?>
        <link href="<?= $e($asset($moduleCss)) ?>" rel="stylesheet" data-module-style="<?= $e($moduleName) ?>">
    <?php endforeach; ?>
</head>
<body class="dashboard-page">

<div class="sidebar-backdrop" data-sidebar-close></div>

<aside class="sidebar" id="sidebar" aria-label="Main navigation">
    <div class="sidebar__brand">
        <span class="brand-mark"><i class="bi bi-box-seam-fill"></i></span>
        <span class="brand-name">Inventory Platform</span>
        <button type="button" class="sidebar__close" data-sidebar-close aria-label="Close menu"><i class="bi bi-x-lg"></i></button>
    </div>

    <div class="dd workspace">
        <button type="button" class="workspace__btn" data-dropdown aria-haspopup="true" aria-expanded="false">
            <span class="workspace__logo"><i class="bi bi-building"></i></span>
            <span class="workspace__text">
                <span class="workspace__name"><?= $e($companyName) ?></span>
                <?php if ($companyLocation !== ''): ?>
                    <span class="workspace__sub"><?= $e($companyLocation) ?></span>
                <?php endif; ?>
            </span>
            <i class="bi bi-chevron-down workspace__chev"></i>
        </button>
        <div class="ds-menu ds-menu--left" role="menu">
            <div class="ds-menu__label">Current workspace</div>
            <span class="ds-menu__item is-current" role="menuitem"><i class="bi bi-check2"></i><?= $e($companyName) ?></span>
        </div>
    </div>

    <nav class="sidebar__nav">
        <ul class="nav-list">
            <?php foreach ($nav as $entry): ?>
                <?php if ($entry['type'] === 'link'): ?>
                    <?php $active = $moduleName === ($entry['module'] ?? ''); ?>
                    <li>
                        <a class="nav-item<?= $active ? ' is-active' : '' ?>"
                           href="<?= $e($entry['href']) ?>"
                           data-module-link
                           data-module="<?= $e($entry['module']) ?>"
                           <?= $active ? 'aria-current="page"' : '' ?>>
                            <i class="bi <?= $e($entry['icon']) ?>"></i><span><?= $e($entry['label']) ?></span>
                        </a>
                    </li>
                <?php else: ?>
                    <?php $gid = 'nav-' . strtolower(preg_replace('/[^a-z0-9]+/i', '-', $entry['label'])); ?>
                    <li class="nav-group<?= $entry['open'] ? ' is-open' : '' ?>">
                        <button type="button" class="nav-item nav-item--group" data-nav-toggle
                                aria-expanded="<?= $entry['open'] ? 'true' : 'false' ?>" aria-controls="<?= $e($gid) ?>">
                            <i class="bi <?= $e($entry['icon']) ?>"></i><span><?= $e($entry['label']) ?></span>
                            <i class="bi bi-chevron-down nav-chev"></i>
                        </button>
                        <div class="nav-sub" id="<?= $e($gid) ?>">
                            <ul class="nav-sub__list">
                                

                                <?php foreach ($entry['items'] as $item): ?>
                                    <?php
                                    $label = $item[0];
                                    $icon = $item[1];
                                    $itemModule = $item[2] ?? null;

                                    $route = strtolower(
                                        trim(
                                            preg_replace('/[^a-z0-9]+/i', '-', $label),
                                            '-'
                                        )
                                    );

                                    $active = $itemModule !== null
                                        && $moduleName === $itemModule;

                                    $itemHref = $itemModule !== null
                                        ? $basePath . '/dashboard.php?module=' . rawurlencode($itemModule)
                                        : '#';
                                    ?>
                                    <li>
                                        <a class="nav-item nav-item--sub<?= $active ? ' is-active' : '' ?>"
                                           href="<?= $e($itemHref) ?>"
                                           data-route="<?= $e($route) ?>"
                                           <?php if ($itemModule !== null): ?>
                                               data-module-link
                                               data-module="<?= $e($itemModule) ?>"
                                           <?php endif; ?>
                                           <?= $active ? 'aria-current="page"' : '' ?>>
                                            <i class="bi <?= $e($icon) ?>"></i>
                                            <span><?= $e($label) ?></span>
                                        </a>
                                    </li>
                                <?php endforeach; ?>

                            </ul>
                        </div>
                    </li>
                <?php endif; ?>
            <?php endforeach; ?>
        </ul>

        <div class="nav-section-label">Coming soon</div>
        <ul class="nav-list nav-list--soon">
            <?php foreach ($comingSoon as [$label, $icon]): ?>
                <li>
                    <span class="nav-item is-disabled" aria-disabled="true">
                        <i class="bi <?= $e($icon) ?>"></i><span><?= $e($label) ?></span>
                        <span class="soon-badge">Soon</span>
                    </span>
                </li>
            <?php endforeach; ?>
        </ul>
    </nav>
</aside>

<div class="shell">
    <header class="topbar">
        <button type="button" class="icon-btn topbar__menu" data-sidebar-open aria-label="Open menu">
            <i class="bi bi-list"></i>
        </button>

        <form class="search" role="search" id="global-search" autocomplete="off">
            <i class="bi bi-search search__icon"></i>
            <input type="search" class="search__input" id="global-search-input"
                   placeholder="Search products, suppliers, customers, or ask AI..."
                   aria-label="Search products, suppliers, customers, or ask AI">
            <kbd class="search__kbd" aria-hidden="true">âŒ˜ K</kbd>
        </form>

        <div class="topbar__right">
            <label class="range" title="Date range">
                <select id="range-select" class="range__select" aria-label="Date range">
                    <option>Today</option>
                    <option>This Week</option>
                    <option selected>This Month</option>
                    <option>This Quarter</option>
                    <option>This Year</option>
                </select>
                <i class="bi bi-chevron-down"></i>
            </label>

            <div class="dd">
                <button type="button" class="icon-btn" data-dropdown aria-haspopup="true" aria-expanded="false" aria-label="Notifications">
                    <i class="bi bi-bell"></i>
                    <?php if ($notifications): ?><span class="icon-btn__badge"><?= count($notifications) ?></span><?php endif; ?>
                </button>
                <div class="ds-menu ds-menu--notif" role="menu">
                    <div class="ds-menu__label">Notifications</div>
                    <?php foreach ($notifications as $n): ?>
                        <a class="notif" href="#" role="menuitem">
                            <span class="tile tile--sm tile--<?= $e($n['tone']) ?>"><i class="bi <?= $e($n['icon']) ?>"></i></span>
                            <span class="notif__text"><?= $e($n['text']) ?><small><?= $e($n['time']) ?></small></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="dd">
                <button type="button" class="profile" data-dropdown aria-haspopup="true" aria-expanded="false">
                    <span class="avatar" aria-hidden="true"><?= $e($initials) ?></span>
                    <span class="profile__text">
                        <span class="profile__name"><?= $e($userName) ?></span>
                        <span class="profile__role"><?= $e($userRole) ?></span>
                    </span>
                    <i class="bi bi-chevron-down profile__chev"></i>
                </button>
                <div class="ds-menu" role="menu">
                    <?php if ($userEmail !== ''): ?>
                        <div class="ds-menu__label"><?= $e($userEmail) ?></div>
                    <?php endif; ?>
                    <a class="ds-menu__item" href="#" role="menuitem"><i class="bi bi-person"></i>My Profile</a>
                    <a class="ds-menu__item" href="#" role="menuitem"><i class="bi bi-gear"></i>Account Settings</a>
                    <a class="ds-menu__item" href="#" role="menuitem"><i class="bi bi-bell"></i>Notifications</a>
                    <div class="ds-menu__sep"></div>
                    <button type="button" class="ds-menu__item ds-menu__item--danger" id="logout-btn" role="menuitem">
                        <i class="bi bi-box-arrow-right"></i>Logout
                    </button>
                </div>
            </div>
        </div>
    </header>

    <main class="page" id="main" data-module-content data-current-module="<?= $e($moduleName) ?>">
        <?= $initialModuleHtml ?>
    </main>
</div>

<div class="toast-stack" id="toast-stack" aria-live="polite" aria-atomic="true"></div>

<script>window.STOCKMIND_DASHBOARD = <?= $clientJson ?>;</script>
<script src="https://code.jquery.com/jquery-3.7.1.min.js" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
<script src="<?= $e($asset('assets/js/csrf.js')) ?>"></script>
<script src="<?= $e($asset('assets/js/api.js')) ?>"></script>
<script src="<?= $e($asset('assets/js/app.js')) ?>"></script>
<script src="<?= $e($asset('assets/js/dashboard.js')) ?>"></script>
<script src="<?= $e($asset('assets/js/module-loader.js')) ?>"></script>
</body>
</html>
