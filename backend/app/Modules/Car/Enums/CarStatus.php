<?php

namespace App\Modules\Car\Enums;

/**
 * Car lifecycle (docs/06-car-domain.md). Transitions are performed only by explicit
 * business actions (purchase/sale tasks), never by generic updates.
 */
enum CarStatus: string
{
    case Purchased = 'PURCHASED';
    case InStock = 'IN_STOCK';
    case Preparation = 'PREPARATION';
    case ReadyForSale = 'READY_FOR_SALE';
    case Sold = 'SOLD';
    case Completed = 'COMPLETED';

    /**
     * @return array<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
