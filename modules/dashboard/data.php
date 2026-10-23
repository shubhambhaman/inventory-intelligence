<?php
declare(strict_types=1);

// Dashboard presentation data for the reusable dashboard module.

$kpis = [
    ['label' => 'Total Inventory Value', 'value' => '₹2.84 Crore', 'delta' => '12%', 'note' => 'vs last month', 'icon' => 'bi-currency-rupee', 'tone' => 'green'],
    ['label' => 'Total SKUs',            'value' => '4,200',       'delta' => '3%',  'note' => 'vs last month', 'icon' => 'bi-box-seam-fill',   'tone' => 'violet'],
    ['label' => 'Total Warehouses',      'value' => '3',           'delta' => null,  'note' => 'Ludhiana, Delhi, Chandigarh', 'icon' => 'bi-buildings-fill', 'tone' => 'blue'],
    ['label' => 'Active Suppliers',      'value' => '85',          'delta' => '5%',  'note' => 'vs last month', 'icon' => 'bi-people-fill',     'tone' => 'teal'],
    ['label' => 'Active Customers',      'value' => '1,200',       'delta' => '8%',  'note' => 'vs last month', 'icon' => 'bi-person-badge-fill', 'tone' => 'orange'],
];

$health = [
    'total' => '₹2.84 Cr',
    'items' => [
        ['key' => 'healthy', 'label' => 'Healthy Stock', 'pct' => 61, 'value' => '₹1.73 Cr', 'color' => '#1faa72'],
        ['key' => 'excess',  'label' => 'Excess Stock',  'pct' => 15, 'value' => '₹42.6 L',  'color' => '#f08a24'],
        ['key' => 'slow',    'label' => 'Slow Moving',   'pct' => 11, 'value' => '₹31.2 L',  'color' => '#f2c23c'],
        ['key' => 'dead',    'label' => 'Dead Stock',    'pct' => 6,  'value' => '₹17.0 L',  'color' => '#6b7686'],
        ['key' => 'low',     'label' => 'Low Stock',     'pct' => 7,  'value' => '₹19.9 L',  'color' => '#3b6cf6'],
    ],
];

// Values in ₹ Crore. Keys = range in months (matches the dropdown).
$trend = [
    '3'  => ['labels' => ['Apr', 'May', 'Jun'], 'values' => [2.52, 2.74, 2.84]],
    '6'  => ['labels' => ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun'], 'values' => [2.15, 2.40, 2.28, 2.52, 2.74, 2.84]],
    '12' => ['labels' => ['Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec', 'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun'],
             'values' => [1.62, 1.71, 1.80, 1.95, 2.02, 2.10, 2.15, 2.40, 2.28, 2.52, 2.74, 2.84]],
];

$stockStatus = [
    ['label' => 'Low Stock',     'count' => 37, 'icon' => 'bi-arrow-down-circle-fill',   'tone' => 'blue'],
    ['label' => 'Stockout Risk', 'count' => 7,  'icon' => 'bi-exclamation-triangle-fill', 'tone' => 'red'],
    ['label' => 'Excess Stock',  'count' => 18, 'icon' => 'bi-layers-fill',              'tone' => 'orange'],
    ['label' => 'Slow Moving',   'count' => 42, 'icon' => 'bi-hourglass-split',          'tone' => 'amber'],
    ['label' => 'Dead Stock',    'count' => 11, 'icon' => 'bi-slash-circle-fill',        'tone' => 'slate'],
];

$decisions = [
    ['priority' => 'High',   'item' => 'Bearing 6205', 'type' => 'Purchase',       'typeIcon' => 'bi-cart-plus',        'rec' => 'Buy 1,200 units',            'recSub' => '',                'impact' => 'Avoid stockout',                'impactSub' => '₹4.2L revenue at risk', 'confidence' => 93],
    ['priority' => 'High',   'item' => 'Cable 4mm',    'type' => 'Transfer',       'typeIcon' => 'bi-arrow-left-right', 'rec' => 'Move 300 units',             'recSub' => 'Delhi → Ludhiana', 'impact' => '₹1.2L opportunity',           'impactSub' => '',                      'confidence' => 87],
    ['priority' => 'Medium', 'item' => 'Motor X1',     'type' => 'Excess',         'typeIcon' => 'bi-layers',           'rec' => 'Reduce 500 units',           'recSub' => '',                'impact' => 'Free up ₹2.4L working capital', 'impactSub' => '',                      'confidence' => 82],
    ['priority' => 'Medium', 'item' => 'Warehouse 2',  'type' => 'Reconciliation', 'typeIcon' => 'bi-clipboard2-check', 'rec' => 'Stock variance -86 units',   'recSub' => '',                'impact' => '₹74K discrepancy',             'impactSub' => '',                      'confidence' => 78],
    ['priority' => 'Low',    'item' => 'Gear Box',     'type' => 'Slow Moving',    'typeIcon' => 'bi-hourglass-split',  'rec' => 'Review inventory',           'recSub' => '',                'impact' => 'Tied up capital ₹1.8L',        'impactSub' => '',                      'confidence' => 71],
];

$pendingDecisions = 7; // total open decisions (the table shows a preview of 5)

$assistantSuggestions = [
    'What will stock out this week?',
    'Show me excess inventory',
    'Which warehouse has highest value?',
    'Why are you recommending this purchase?',
    'Which products are slow moving?',
];

$quickActions = [
    ['key' => 'add-product',      'title' => 'Add New Product',   'sub' => 'Create a new SKU',            'icon' => 'bi-box-seam',          'tone' => 'blue'],
    ['key' => 'upload-inventory', 'title' => 'Upload Inventory',  'sub' => 'Import from Excel/CSV',       'icon' => 'bi-cloud-arrow-up',    'tone' => 'green'],
    ['key' => 'record-purchase',  'title' => 'Record Purchase',   'sub' => 'Add incoming stock',          'icon' => 'bi-box-arrow-in-down', 'tone' => 'orange'],
    ['key' => 'record-sale',      'title' => 'Record Sale',       'sub' => 'Add outgoing stock',          'icon' => 'bi-box-arrow-up',      'tone' => 'violet'],
    ['key' => 'transfer-stock',   'title' => 'Transfer Stock',    'sub' => 'Move between warehouses',     'icon' => 'bi-arrow-left-right',  'tone' => 'teal'],
    ['key' => 'stock-adjustment', 'title' => 'Stock Adjustment',  'sub' => 'Correct stock levels',        'icon' => 'bi-sliders',           'tone' => 'amber'],
];

$modules = [
    ['title' => 'Inventory',            'icon' => 'bi-box-seam-fill',    'tone' => 'blue',   'status' => 'active', 'items' => ['Stock Management', 'Inventory Intelligence', 'Replenishment']],
    ['title' => 'Purchasing',           'icon' => 'bi-cart3',            'tone' => 'orange', 'status' => 'soon',   'items' => ['Purchase Orders', 'Supplier Management', 'Approval Workflow']],
    ['title' => 'Sales & Orders',       'icon' => 'bi-receipt',          'tone' => 'violet', 'status' => 'soon',   'items' => ['Sales Orders', 'Order Management', 'Dispatch & Delivery']],
    ['title' => 'Customers (CRM)',      'icon' => 'bi-people-fill',      'tone' => 'teal',   'status' => 'active', 'items' => ['Customer Database', 'Order History', 'Credit Management']],
    ['title' => 'Collections',          'icon' => 'bi-cash-coin',        'tone' => 'green',  'status' => 'soon',   'items' => ['Receivables Tracking', 'Payment Reminders', 'Aging Reports']],
    ['title' => 'Accounting & Finance', 'icon' => 'bi-calculator-fill',  'tone' => 'amber',  'status' => 'soon',   'items' => ['GST Compliance', 'Financial Reports', 'Profit & Loss']],
];

$notifications = [
    ['icon' => 'bi-exclamation-triangle-fill', 'tone' => 'red',    'text' => 'Bearing 6205 may stock out within 6 days', 'time' => '10 min ago'],
    ['icon' => 'bi-arrow-left-right',          'tone' => 'blue',   'text' => 'Transfer Delhi → Ludhiana needs review',   'time' => '1 hour ago'],
    ['icon' => 'bi-clipboard2-check',          'tone' => 'orange', 'text' => 'Warehouse 2 shows a stock variance',       'time' => 'Yesterday'],
];

$tabs = ['Overview', 'Inventory Health', 'Stock Movement', 'Replenishment', 'Financial Impact', 'Warehouse Performance'];
