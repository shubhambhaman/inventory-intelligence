
<?php


/**
 * Database-free tests for StockMovementModel.
 * Run from project root:
 *   php test/stock_movement_model_test.php
 *
 * This suite never opens a database connection and never writes application data.
 */
require_once dirname(__DIR__) . '/src/Inventory/StockMovementModel.php';

use App\Inventory\StockMovementModel;

$passed = 0;
$failed = 0;

$assert = static function (bool $condition, string $message) use (&$passed, &$failed): void {
    if ($condition) {
        ++$passed;
        echo "PASS: {$message}\n";
    } else {
        ++$failed;
        fwrite(STDERR, "FAIL: {$message}\n");
    }
};

$rejects = static function (array $payload, string $message) use ($assert): void {
    try {
        StockMovementModel::validateAndNormalize($payload);
        $assert(false, $message);
    } catch (InvalidArgumentException) {
        $assert(true, $message);
    } catch (Throwable $e) {
        $assert(false, $message . ' (unexpected ' . get_class($e) . ': ' . $e->getMessage() . ')');
    }
};

$base = [
    'product_id' => '12',
    'warehouse_id' => 3,
    'movement_type' => 'purchase',
    'quantity' => '25.500000',
    'unit_cost' => '0.000000',
    'source_type' => 'supplier_invoice',
    'source_reference' => 'INV-1001',
    'occurred_at' => '2026-10-16 10:30:00',
    'idempotency_key' => 'req-unique-1001',
];

// Valid normalization and precision preservation.
try {
    $m = StockMovementModel::validateAndNormalize($base);
    $assert($m['product_id'] === 12, 'product ID normalized to integer');
    $assert($m['warehouse_id'] === 3, 'warehouse ID normalized to integer');
    $assert($m['movement_type'] === 'PURCHASE', 'movement type normalized to uppercase');
    $assert($m['direction'] === 'in', 'purchase direction is in');
    $assert($m['quantity'] === '25.5', 'quantity normalized without float conversion');
    $assert($m['unit_cost'] === '0', 'zero unit cost accepted and normalized');
    $assert($m['occurred_at'] === '2026-10-16 10:30:00', 'valid timestamp preserved');
    $assert($m['source_reference'] === 'INV-1001', 'source reference preserved');
} catch (Throwable $e) {
    $assert(false, 'valid payload accepted (' . $e->getMessage() . ')');
}

// Every fixed-direction type maps correctly.
$directions = [
    'OPENING' => 'in', 'PURCHASE' => 'in', 'SALE' => 'out',
    'RETURN_IN' => 'in', 'RETURN_OUT' => 'out',
    'TRANSFER_IN' => 'in', 'TRANSFER_OUT' => 'out',
];
foreach ($directions as $type => $direction) {
    try {
        $m = StockMovementModel::validateAndNormalize(array_replace($base, ['movement_type' => $type]));
        $assert($m['direction'] === $direction, "{$type} derives {$direction} direction");
    } catch (Throwable $e) {
        $assert(false, "{$type} accepted ('{$e->getMessage()}')");
    }
}

// Adjustment requires a direction and accepts either direction.
foreach (['in', 'out'] as $direction) {
    try {
        $m = StockMovementModel::validateAndNormalize(array_replace($base, ['movement_type' => 'adjustment', 'direction' => $direction]));
        $assert($m['direction'] === $direction, "adjustment {$direction} direction accepted");
    } catch (Throwable $e) {
        $assert(false, "adjustment {$direction} direction accepted ('{$e->getMessage()}')");
    }
}
$rejects(array_replace($base, ['movement_type' => 'adjustment']), 'adjustment without direction rejected');
$rejects(array_replace($base, ['movement_type' => 'SALE', 'direction' => 'in']), 'contradictory fixed direction rejected');
$rejects(array_replace($base, ['direction' => 'sideways']), 'invalid direction rejected');

// Required fields and identifier validation, including negative PHP integers.
foreach ([
    ['product_id' => 0],
    ['product_id' => -1],
    ['product_id' => '-1'],
    ['product_id' => '999999999999999999999999999'],
    ['product_id' => '1.5'],
    ['warehouse_id' => 0],
    ['warehouse_id' => -2],
    ['warehouse_id' => '-2'],
    ['warehouse_id' => '999999999999999999999999999'],
    ['movement_type' => 'UNKNOWN'],
    ['movement_type' => ''],
    ['source_type' => '  '],
    ['source_reference' => ''],
    ['idempotency_key' => ''],
] as $case) {
    $rejects(array_replace($base, $case), 'invalid required field rejected: ' . array_key_first($case));
}

// Quantity and unit cost boundaries.
foreach (['0', '-1', '1.1234567', '100000000000000', '1e3', 'abc', ''] as $quantity) {
    $rejects(array_replace($base, ['quantity' => $quantity]), 'invalid quantity rejected: ' . var_export($quantity, true));
}
foreach (['-0.01', '1.1234567', '100000000000000', '1e2', 'abc'] as $cost) {
    $rejects(array_replace($base, ['unit_cost' => $cost]), 'invalid unit cost rejected: ' . var_export($cost, true));
}
try {
    $m = StockMovementModel::validateAndNormalize(array_replace($base, ['unit_cost' => null]));
    $assert($m['unit_cost'] === null, 'nullable unit cost accepted');
} catch (Throwable $e) {
    $assert(false, 'nullable unit cost accepted (' . $e->getMessage() . ')');
}

// Date validation and source field lengths.
foreach (['2026-02-30 12:00:00', '2026-10-16T10:30:00', '16-10-2026 10:30:00', ''] as $date) {
    $rejects(array_replace($base, ['occurred_at' => $date]), 'invalid occurred_at rejected: ' . var_export($date, true));
}
$rejects(array_replace($base, ['source_type' => str_repeat('s', 51)]), 'source_type over 50 chars rejected');
$rejects(array_replace($base, ['source_reference' => str_repeat('r', 101)]), 'source_reference over 100 chars rejected');
$rejects(array_replace($base, ['idempotency_key' => str_repeat('k', 256)]), 'idempotency key over 255 chars rejected');
$assert(
    StockMovementModel::validateAndNormalize(array_replace($base, ['source_type' => str_repeat('s', 50)]))['source_type'] === str_repeat('s', 50),
    'source_type at 50-char limit accepted'
);
$assert(
    StockMovementModel::validateAndNormalize(array_replace($base, ['source_reference' => str_repeat('r', 100)]))['source_reference'] === str_repeat('r', 100),
    'source_reference at 100-char limit accepted'
);
$assert(
    StockMovementModel::validateAndNormalize(array_replace($base, ['idempotency_key' => str_repeat('k', 255)]))['idempotency_key'] === str_repeat('k', 255),
    'idempotency key at 255-char limit accepted'
);

// Server-controlled columns cannot be supplied by clients.
foreach (['id', 'movement_id', 'tenant_id', 'created_by', 'posted_by', 'status', 'posted_at', 'created_at', 'request_hash', 'reversal_of_movement_id'] as $field) {
    $rejects(array_replace($base, [$field => 1]), "client-supplied server-managed field '{$field}' rejected");
}

$expectedTypes = ['OPENING', 'PURCHASE', 'SALE', 'RETURN_IN', 'RETURN_OUT', 'TRANSFER_IN', 'TRANSFER_OUT', 'ADJUSTMENT'];
$assert(array_keys(StockMovementModel::movementTypes()) === $expectedTypes, 'canonical movement types match expected list');

printf("\nResults: %d passed, %d failed\n", $passed, $failed);
exit($failed === 0 ? 0 : 1);
