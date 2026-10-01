<?php

namespace App\Modules\Car\Support;

use App\Modules\Car\Enums\CarStatus;

/**
 * Explicit lifecycle (docs/06-car-domain.md):
 * PURCHASED → IN_STOCK → PREPARATION ⇄ READY_FOR_SALE → SOLD → COMPLETED
 *
 * - SOLD is entered only by recording a sale (and left only by reversing it or completing).
 * - COMPLETED requires the sale to be fully paid; it can be reopened back to SOLD.
 */
final class CarLifecycle
{
    /** @var array<string, array<int, CarStatus>> manual transitions (status changes without a financial record) */
    private const MANUAL = [
        'PURCHASED' => [CarStatus::InStock],
        'IN_STOCK' => [CarStatus::Preparation, CarStatus::ReadyForSale],
        'PREPARATION' => [CarStatus::InStock, CarStatus::ReadyForSale],
        'READY_FOR_SALE' => [CarStatus::InStock, CarStatus::Preparation],
        'SOLD' => [CarStatus::Completed],
        'COMPLETED' => [CarStatus::Sold],
    ];

    /** Statuses from which a car may be sold. */
    public const SELLABLE = [CarStatus::InStock, CarStatus::Preparation, CarStatus::ReadyForSale];

    public static function canTransition(CarStatus $from, CarStatus $to): bool
    {
        return in_array($to, self::MANUAL[$from->value] ?? [], true);
    }

    /**
     * @return array<int, CarStatus>
     */
    public static function nextStatuses(CarStatus $from): array
    {
        return self::MANUAL[$from->value] ?? [];
    }

    public static function isSellable(CarStatus $status): bool
    {
        return in_array($status, self::SELLABLE, true);
    }
}
