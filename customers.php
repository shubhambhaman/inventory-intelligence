<?php
declare(strict_types=1);

$basePath = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
if ($basePath === '.' || $basePath === '/') {
    $basePath = '';
}

header('Location: ' . $basePath . '/dashboard.php?module=customers', true, 302);
exit;
