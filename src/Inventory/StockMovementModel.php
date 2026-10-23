<?php

declare(strict_types=1);

namespace App\Inventory;

use InvalidArgumentException;

/**
 * Validates and normalizes stock movement input.
 *
 * This class intentionally does not persist data. Tenant identity, actor,
 * posting state, posted timestamps and movement IDs must be supplied by the
 * authenticated posting service, never trusted from client input.
 */
final class StockMovementModel
{
    /** @var array<string, string|null> Canonical types; ADJUSTMENT needs direction. */
    private const MOVEMENT_TYPES = [
        'OPENING'      => 'in',
        'PURCHASE'     => 'in',
        'SALE'         => 'out',
        'RETURN_IN'    => 'in',
        'RETURN_OUT'   => 'out',
        'TRANSFER_IN'  => 'in',
        'TRANSFER_OUT' => 'out',
        'ADJUSTMENT'   => null,
    ];

    /** @var list<string> */
    private const SERVER_MANAGED_FIELDS = [
        'id',
        'movement_id',
        'tenant_id',
        'created_by',
        'posted_by',
        'status',
        'posted_at',
        'created_at',
        'request_hash',
        'reversal_of_movement_id',
    ];

    /**
     * @return array{
     *   product_id:int,
     *   warehouse_id:int,
     *   movement_type:string,
     *   direction:string,
     *   quantity:string,
     *   unit_cost:?string,
     *   source_type:string,
     *   source_reference:string,
     *   idempotency_key:string,
     *   occurred_at:string
     * }
     */
    public static function validateAndNormalize(array $input): array
    {
        foreach (self::SERVER_MANAGED_FIELDS as $field) {
            if (array_key_exists($field, $input)) {
                throw new InvalidArgumentException("Field '{$field}' is managed by the server.");
            }
        }

        $productId = self::positiveInteger($input['product_id'] ?? null, 'product_id');
        $warehouseId = self::positiveInteger($input['warehouse_id'] ?? null, 'warehouse_id');

        $movementType = strtoupper(self::requiredString($input['movement_type'] ?? null, 'movement_type'));
        if (!array_key_exists($movementType, self::MOVEMENT_TYPES)) {
            throw new InvalidArgumentException('Unsupported movement_type.');
        }

        $direction = self::resolveDirection($movementType, $input);
        $quantity = self::normalizeDecimal($input['quantity'] ?? null, 'quantity', false);

        $unitCost = null;
        if (array_key_exists('unit_cost', $input) && $input['unit_cost'] !== null) {
            $unitCost = self::normalizeDecimal($input['unit_cost'], 'unit_cost', true);
        }

        $sourceType = self::requiredString($input['source_type'] ?? null, 'source_type');
        if (strlen($sourceType) > 50) {
            throw new InvalidArgumentException('source_type must not exceed 50 characters.');
        }

        $sourceReference = self::requiredString($input['source_reference'] ?? null, 'source_reference');
        if (strlen($sourceReference) > 100) {
            throw new InvalidArgumentException('source_reference must not exceed 100 characters.');
        }

        $idempotencyKey = self::requiredString($input['idempotency_key'] ?? null, 'idempotency_key');
        if (strlen($idempotencyKey) > 255) {
            throw new InvalidArgumentException('idempotency_key must not exceed 255 characters.');
        }

        return [
            'product_id' => $productId,
            'warehouse_id' => $warehouseId,
            'movement_type' => $movementType,
            'direction' => $direction,
            'quantity' => $quantity,
            'unit_cost' => $unitCost,
            'source_type' => $sourceType,
            'source_reference' => $sourceReference,
            'idempotency_key' => $idempotencyKey,
            'occurred_at' => self::validateDateTime($input['occurred_at'] ?? null),
        ];
    }

    /**
     * Direction for a movement. ADJUSTMENT requires an explicit in/out value.
     * For fixed-direction types, a supplied contradictory direction is rejected.
     */
    public static function directionFor(string $movementType, ?string $adjustmentDirection = null): string
    {
        $movementType = strtoupper(trim($movementType));
        if (!array_key_exists($movementType, self::MOVEMENT_TYPES)) {
            throw new InvalidArgumentException('Unsupported movement_type.');
        }

        $expected = self::MOVEMENT_TYPES[$movementType];
        if ($expected === null) {
            return self::validateDirection($adjustmentDirection);
        }

        if ($adjustmentDirection !== null && self::validateDirection($adjustmentDirection) !== $expected) {
            throw new InvalidArgumentException('Direction does not match movement_type.');
        }

        return $expected;
    }

    /** @return array<string, string|null> */
    public static function movementTypes(): array
    {
        return self::MOVEMENT_TYPES;
    }

    private static function resolveDirection(string $movementType, array $input): string
    {
        $provided = null;
        if (array_key_exists('direction', $input)) {
            $provided = self::validateDirection($input['direction']);
        }

        return self::directionFor($movementType, $provided);
    }

    private static function validateDirection(mixed $value): string
    {
        if (!is_string($value)) {
            throw new InvalidArgumentException('direction must be either in or out.');
        }
        $direction = strtolower(trim($value));
        if (!in_array($direction, ['in', 'out'], true)) {
            throw new InvalidArgumentException('direction must be either in or out.');
        }
        return $direction;
    }

    private static function positiveInteger(mixed $value, string $field): int
    {
        // ctype_digit() is only applied to strings. Negative PHP integers
        // otherwise pass the type check and can reach the return cast below.
        if (is_int($value) && $value < 0) {
            throw new InvalidArgumentException("{$field} must be a positive integer.");
        }

        if (!is_int($value) && !(is_string($value) && ctype_digit($value))) {
            throw new InvalidArgumentException("{$field} must be a positive integer.");
        }

        $digits = ltrim((string) $value, '0');
        if ($digits === '') {
            throw new InvalidArgumentException("{$field} must be greater than zero.");
        }

        $max = (string) PHP_INT_MAX;
        if (strlen($digits) > strlen($max) || (strlen($digits) === strlen($max) && strcmp($digits, $max) > 0)) {
            throw new InvalidArgumentException("{$field} exceeds the supported integer range.");
        }

        return (int) $digits;
    }

    private static function requiredString(mixed $value, string $field): string
    {
        if (!is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException("{$field} is required.");
        }
        return trim($value);
    }

    /**
     * Normalize a non-negative decimal as text, avoiding floats entirely.
     * Supports DECIMAL(20,6): up to 14 integer digits and 6 fractional digits.
     */
    private static function normalizeDecimal(mixed $value, string $field, bool $allowZero): string
    {
        if (!is_string($value) && !is_int($value)) {
            throw new InvalidArgumentException("{$field} must be supplied as a decimal string or integer.");
        }

        $decimal = trim((string) $value);
        if (!preg_match('/^\d+(?:\.\d{1,6})?$/D', $decimal)) {
            throw new InvalidArgumentException("{$field} must be a non-negative decimal with at most 6 decimal places.");
        }

        [$whole, $fraction] = array_pad(explode('.', $decimal, 2), 2, '');
        $whole = ltrim($whole, '0');
        $whole = $whole === '' ? '0' : $whole;
        if (strlen($whole) > 14) {
            throw new InvalidArgumentException("{$field} exceeds the supported DECIMAL(20,6) range.");
        }

        $fraction = rtrim($fraction, '0');
        $isZero = $whole === '0' && $fraction === '';
        if ($isZero && !$allowZero) {
            throw new InvalidArgumentException("{$field} must be greater than zero.");
        }

        return $fraction === '' ? $whole : $whole . '.' . $fraction;
    }

    private static function validateDateTime(mixed $value): string
    {
        if (!is_string($value)) {
            throw new InvalidArgumentException('occurred_at is required in YYYY-MM-DD HH:MM:SS format.');
        }

        $dateTime = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value);
        $errors = \DateTimeImmutable::getLastErrors();
        if (
            $dateTime === false
            || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $dateTime->format('Y-m-d H:i:s') !== $value
        ) {
            throw new InvalidArgumentException('occurred_at must be a valid YYYY-MM-DD HH:MM:SS date/time.');
        }

        return $value;
    }
}
