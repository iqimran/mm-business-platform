<?php

namespace App\Modules\Car\Actions;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Car\Models\CarImage;
use App\Modules\Identity\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class DeleteCarImage
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(User $actor, CarImage $image): void
    {
        $car = $image->car;

        DB::transaction(function () use ($actor, $image, $car) {
            $image->delete();

            $this->audit->record('car.image_deleted', 'car', $car->id, $actor->id, $car->branch_id,
                oldValues: ['image_id' => $image->id, 'original_name' => $image->original_name]);
        });

        Storage::disk($image->disk)->delete($image->path);
    }
}
