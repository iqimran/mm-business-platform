<?php

namespace App\Modules\Car\Actions;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Car\Enums\CarStatus;
use App\Modules\Car\Models\Car;
use App\Modules\Identity\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Only cars that never reached a sale can be deleted. Later tasks add restrictions
 * for cars with financial records (foreign keys block deletion as well).
 */
class DeleteCar
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(User $actor, Car $car): void
    {
        if (in_array($car->status, [CarStatus::Sold, CarStatus::Completed], true)) {
            throw new ConflictHttpException('Sold cars cannot be deleted.');
        }

        // Financial history (even reversed) must be preserved; foreign keys enforce this too.
        if ($car->hasFinancialRecords()) {
            throw new ConflictHttpException('Cars with purchase or expense records cannot be deleted.');
        }

        $files = $car->images()->get(['disk', 'path']);

        DB::transaction(function () use ($actor, $car) {
            $old = Arr::except($car->getAttributes(), ['id', 'created_at', 'updated_at']);
            $car->delete(); // image rows cascade

            $this->audit->record('car.deleted', 'car', $car->id, $actor->id, $car->branch_id, oldValues: $old);
        });

        // Remove stored files only once the database change is committed.
        foreach ($files as $file) {
            Storage::disk($file->disk)->delete($file->path);
        }
    }
}
