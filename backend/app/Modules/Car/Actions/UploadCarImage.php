<?php

namespace App\Modules\Car\Actions;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Car\Models\Car;
use App\Modules\Car\Models\CarImage;
use App\Modules\Identity\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Stores the file on the default disk (S3-compatible in production) under a generated name;
 * the client's file name is kept only as metadata.
 */
class UploadCarImage
{
    public const MAX_IMAGES_PER_CAR = 20;

    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(User $actor, Car $car, UploadedFile $file): CarImage
    {
        if ($car->images()->count() >= self::MAX_IMAGES_PER_CAR) {
            throw ValidationException::withMessages(['image' => 'A car can have at most '.self::MAX_IMAGES_PER_CAR.' images.']);
        }

        $disk = config('filesystems.default');
        // Extension from the detected MIME type, never from the client-supplied name.
        $path = Storage::disk($disk)->putFileAs("cars/{$car->id}", $file, Str::ulid()->toBase32().'.'.$file->extension());

        try {
            return DB::transaction(function () use ($actor, $car, $file, $disk, $path) {
                $image = CarImage::create([
                    'car_id' => $car->id,
                    'disk' => $disk,
                    'path' => $path,
                    'original_name' => Str::limit($file->getClientOriginalName(), 250, ''),
                    'mime_type' => $file->getMimeType(),
                    'size_bytes' => $file->getSize(),
                    'sort_order' => (int) $car->images()->max('sort_order') + 1,
                    'uploaded_by' => $actor->id,
                ]);

                $this->audit->record('car.image_uploaded', 'car', $car->id, $actor->id, $car->branch_id,
                    newValues: ['image_id' => $image->id, 'original_name' => $image->original_name, 'size_bytes' => $image->size_bytes]);

                return $image;
            });
        } catch (Throwable $e) {
            Storage::disk($disk)->delete($path);

            throw $e;
        }
    }
}
