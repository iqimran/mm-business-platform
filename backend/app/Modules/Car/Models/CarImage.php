<?php

namespace App\Modules\Car\Models;

use App\Modules\Identity\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Metadata only; the file lives on the configured storage disk (S3-compatible in production).
 */
#[Fillable(['car_id', 'disk', 'path', 'original_name', 'mime_type', 'size_bytes', 'sort_order', 'uploaded_by'])]
class CarImage extends Model
{
    use HasUlids;

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    public function car(): BelongsTo
    {
        return $this->belongsTo(Car::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
