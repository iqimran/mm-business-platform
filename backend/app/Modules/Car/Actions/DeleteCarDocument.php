<?php

namespace App\Modules\Car\Actions;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Car\Models\CarDocument;
use App\Modules\Identity\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Removes a mistaken entry. Renewals should be added as new documents instead, to keep history.
 */
class DeleteCarDocument
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(User $actor, CarDocument $document): void
    {
        DB::transaction(function () use ($actor, $document) {
            $document->delete();

            $this->audit->record('car.document_deleted', 'car_document', $document->id, $actor->id, $document->car->branch_id, oldValues: [
                'car_id' => $document->car_id,
                'type' => $document->type->value,
                'custom_name' => $document->custom_name,
                'document_number' => $document->document_number,
                'expiry_date' => $document->expiry_date->toDateString(),
            ]);
        });
    }
}
