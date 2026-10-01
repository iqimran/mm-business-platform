<?php

namespace App\Modules\Administration\Backups\Http\Resources;

use App\Modules\Administration\Backups\Models\DatabaseBackup;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Metadata only: no SQL, no credentials and no server filesystem paths.
 *
 * @mixin DatabaseBackup
 */
class DatabaseBackupResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'status' => $this->status,
            'filename' => $this->filename,
            'size_bytes' => $this->size_bytes,
            'checksum_sha256' => $this->checksum_sha256,
            'error_message' => $this->error_message,
            'requested_by' => $this->whenLoaded('requester', fn () => $this->requester?->only('id', 'name')),
            'downloadable' => $this->status === DatabaseBackup::COMPLETED,
            'created_at' => $this->created_at?->toIso8601String(),
            'started_at' => $this->started_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'deleted_at' => $this->deleted_at?->toIso8601String(),
        ];
    }
}
