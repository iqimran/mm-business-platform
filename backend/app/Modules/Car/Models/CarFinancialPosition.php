<?php

namespace App\Modules\Car\Models;

use App\Modules\Branch\Models\Branch;
use App\Modules\Car\Enums\CarStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Read-only model over the car_financial_positions view (one row per car, minor units).
 */
class CarFinancialPosition extends Model
{
    protected $table = 'car_financial_positions';

    protected $primaryKey = 'car_id';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'status' => CarStatus::class,
            'model_year' => 'integer',
            'purchase_date' => 'date',
            'sale_date' => 'date',
            'purchase_cost' => 'integer',
            'expenses_total' => 'integer',
            'total_investment' => 'integer',
            'sale_amount' => 'integer',
            'party_received' => 'integer',
            'party_due' => 'integer',
            'dealer_purchase_amount' => 'integer',
            'dealer_paid' => 'integer',
            'dealer_payable' => 'integer',
            'profit' => 'integer',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function dealer(): BelongsTo
    {
        return $this->belongsTo(CarDealer::class, 'dealer_id');
    }

    public function party(): BelongsTo
    {
        return $this->belongsTo(CarParty::class, 'party_id');
    }
}
