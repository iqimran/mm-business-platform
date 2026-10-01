<?php

namespace App\Modules\Restaurant\Actions;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Identity\Models\User;
use App\Modules\Restaurant\Models\FoodSale;
use App\Modules\Shared\Support\Money;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Reverses (cancels) a food sale. Its payments must be reversed first, so money is never left
 * attached to a cancelled sale. The sale and its lines stay in history.
 */
class ReverseFoodSale
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(User $actor, FoodSale $sale, string $reason): FoodSale
    {
        return DB::transaction(function () use ($actor, $sale, $reason) {
            $sale = FoodSale::whereKey($sale->getKey())->lockForUpdate()->firstOrFail();

            if ($sale->isReversed()) {
                throw new ConflictHttpException('This sale has already been reversed.');
            }
            if ($sale->payments()->active()->exists()) {
                throw new ConflictHttpException('This sale has payments. Reverse its payments first.');
            }

            $sale->forceFill([
                'reversed_at' => now(),
                'reversed_by' => $actor->id,
                'reversal_reason' => $reason,
            ])->save();

            $this->audit->record('restaurant.sale.reversed', 'restaurant_sale', $sale->id, $actor->id, $sale->branch_id,
                oldValues: ['total' => Money::toDecimal($sale->total_minor), 'reversed' => false],
                newValues: ['sale_no' => $sale->sale_no, 'reversed' => true, 'reason' => $reason]);

            return $sale;
        });
    }
}
