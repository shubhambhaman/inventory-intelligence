
<?php


require_once __DIR__ . '/../src/Inventory/StockMovementModel.php';

use App\Inventory\StockMovementModel;

$reflection = new ReflectionClass(StockMovementModel::class);
$method = $reflection->getMethod('positiveInteger');
$method->setAccessible(true);

$tests = [
    ['negative integer', -1, false],
    ['zero integer', 0, false],
    ['positive integer', 5, true],
    ['numeric string', '12', true],
    ['negative string', '-1', false],
    ['zero string', '0', false],
    ['invalid string', 'abc', false],
    ['integer overflow', '999999999999999999999999999999', false],
];

$passed = 0;
$failed = 0;

foreach ($tests as [$name, $value, $shouldPass]) {
    try {
        $result = $method->invoke(null, $value, 'product_id');
        $actualPass = is_int($result) && $result > 0;
    } catch (Throwable $e) {
        $actualPass = false;
    }

    if ($actualPass === $shouldPass) {
        echo "PASS: {$name}\n";
        $passed++;
    } else {
        echo "FAIL: {$name}\n";
        $failed++;
    }
}

echo "\nResults: {$passed} passed, {$failed} failed.\n";

exit($failed === 0 ? 0 : 1);
