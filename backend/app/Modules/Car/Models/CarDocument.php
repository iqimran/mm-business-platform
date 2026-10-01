<?php

namespace App\Modules\Car\Models;

use App\Modules\Car\Enums\CarDocumentType;
use App\Modules\Identity\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A dated compliance document of a car. Renewals are new rows; the row with the latest
 * expiry per (car, type, custom name) is the current one, older rows are superseded history.
 */
#[Fillable(['car_id', 'type', 'custom_name', 'document_number', 'issue_date', 'expiry_date', 'notes', 'recorded_by'])]
class CarDocument extends Model
{
    use HasUlids;

    protected function casts(): array
    {
        return [
            'type' => CarDocumentType::class,
            'issue_date' => 'date',
            'expiry_date' => 'date',
        ];
    }

    public function car(): BelongsTo
    {
        return $this->belongsTo(Car::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function displayName(): string
    {
        return $this->type === CarDocumentType::Other ? (string) $this->custom_name : $this->type->label();
    }

    /** Documents not superseded by a later one of the same kind for the same car. */
    public function scopeCurrent(Builder $query): Builder
    {
        $table = $this->getTable();

        return $query->whereNotExists(fn ($q) => $q
            ->selectRaw('1')
            ->from("{$table} as newer")
            ->whereColumn('newer.car_id', "{$table}.car_id")
            ->whereColumn('newer.type', "{$table}.type")
            ->whereRaw("coalesce(newer.custom_name, '') = coalesce({$table}.custom_name, '')")
            ->where(fn ($w) => $w
                ->whereColumn('newer.expiry_date', '>', "{$table}.expiry_date")
                ->orWhere(fn ($tie) => $tie
                    ->whereColumn('newer.expiry_date', "{$table}.expiry_date")
                    ->whereColumn('newer.id', '>', "{$table}.id"))));
    }

    /** Limits to documents of cars the user may access (branch isolation). */
    public function scopeAccessibleBy(Builder $query, User $user): Builder
    {
        return $query->whereHas('car', fn (Builder $cars) => $cars->accessibleBy($user));
    }
}
