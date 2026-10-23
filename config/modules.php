<?php
declare(strict_types=1);

/**
 * Server-side module registry.
 *
 * Keep this list explicit. User input may choose one of these keys, but it must
 * never become a filesystem path.
 */

function app_module_registry(): array
{
    $root = dirname(__DIR__);

    return [
        'dashboard' => [
            'name' => 'dashboard',
            'title' => 'Dashboard',
            'permission' => null,
            'view' => $root . '/modules/dashboard/view.php',
            'data' => $root . '/modules/dashboard/data.php',
            'css' => [],
            'js' => [],
        ],
        'customers' => [
            'name' => 'customers',
            'title' => 'Customers',
            'permission' => 'customers.view',
            'view' => $root . '/modules/customers/view.php',
            'data' => null,
            'css' => ['assets/css/modules/customers.css'],
            'js' => ['assets/js/modules/customers.js'],
        ],
        'products' => [
            'name' => 'products',
            'title' => 'Products',
            'permission' => 'products.view',
            'view' => $root . '/modules/products/view.php',
            'data' => null,
            'css' => ['assets/css/modules/products.css'],
            'js' => ['assets/js/modules/products.js'],
        ],
        'suppliers' => [
            'name' => 'suppliers',
            'title' => 'Suppliers',
            'permission' => 'suppliers.view',
            'view' => $root . '/modules/suppliers/view.php',
            'data' => null,
            'css' => ['assets/css/modules/suppliers.css'],
            'js' => ['assets/js/modules/suppliers.js'],
        ],
        'categories' => [
            'name' => 'categories',
            'title' => 'Categories',
            'permission' => 'categories.view',
            'view' => $root . '/modules/categories/view.php',
            'data' => null,
            'css' => ['assets/css/modules/categories.css'],
            'js' => ['assets/js/modules/categories.js'],
        ],
        'units' => [
            'name' => 'units',
            'title' => 'Units',
            'permission' => 'units.view',
            'view' => $root . '/modules/units/view.php',
            'data' => null,
            'css' => ['assets/css/modules/units.css'],
            'js' => ['assets/js/modules/units.js'],
        ],
        'warehouses' => [
            'name' => 'warehouses',
            'title' => 'Warehouses',
            'permission' => 'warehouses.view',
            'view' => $root . '/modules/warehouses/view.php',
            'data' => null,
            'css' => ['assets/css/modules/warehouses.css'],
            'js' => ['assets/js/modules/warehouses.js'],
        ],


    ];
}

function app_module_key(mixed $name): ?string
{
    if (!is_string($name)) {
        return null;
    }

    $name = strtolower(trim($name));

    if ($name === '') {
        return 'dashboard';
    }

    return preg_match('/^[a-z0-9_-]{1,40}$/', $name) ? $name : null;
}

function app_normalize_module_name(mixed $name): string
{
    return app_module_key($name) ?? 'dashboard';
}

function app_module_definition(mixed $name): ?array
{
    $registry = app_module_registry();
    $key = app_module_key($name);

    if ($key === null) {
        return null;
    }

    return $registry[$key] ?? null;
}

function app_base_path(): string
{
    $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
    $dir = rtrim(dirname($script), '/');

    if ($dir === '.' || $dir === '/') {
        return '';
    }

    if (substr($dir, -8) === '/modules') {
        $dir = substr($dir, 0, -8);
    }

    return ($dir === '' || $dir === '.' || $dir === '/') ? '' : $dir;
}

function app_public_module_config(callable $asset): array
{
    $public = [];

    foreach (app_module_registry() as $name => $module) {
        $public[$name] = [
            'title' => $module['title'],
            'css' => array_map($asset, $module['css']),
            'js' => array_map($asset, $module['js']),
        ];
    }

    return $public;
}
