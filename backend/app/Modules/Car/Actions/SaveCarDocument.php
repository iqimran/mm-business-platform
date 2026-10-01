<?php

namespace App\Modules\Car\Actions;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Car\Models\Car;
use App\Modules\Car\Models\CarDocument;
use App\Modules\Identity\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * Adds a document (a renewal is simply a new document) or corrects an existing one.
 */
class SaveCarDocument
{
    private const FIELDS = ['type', 'custom_name', 'document_number', 'issue_date', 'expiry_date', 'notes'];

    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(User $actor, Car $car, array $data, ?CarDocument $document = null): CarDocument
    {
        return DB::transaction(function () use ($actor, $car, $data, $document) {
            if ($document === null) {
                $document = CarDocument::create(Arr::only($data, self::FIELDS) + ['car_id' => $car->id, 'recorded_by' => $actor->id]);

                $this->audit->record('car.document_added', 'car_document', $document->id, $actor->id, $car->branch_id,
                    newValues: ['car_id' => $car->id] + $this->snapshot($document));

                return $document;
            }

            $old = $this->snapshot($document);
            $document->fill(Arr::only($data, self::FIELDS))->save();
            $new = $this->snapshot($document);
            $changed = array_keys(array_diff_assoc(array_map('strval', $new), array_map('strval', $old)));

            if ($changed !== []) {
                $this->audit->record('car.document_updated', 'car_document', $document->id, $actor->id, $car->branch_id,
                    oldValues: Arr::only($old, $changed), newValues: Arr::only($new, $changed));
            }

            return $document;
        });
    }

    /**
     * @return array<string, string|null>
     */
    private function snapshot(CarDocument $document): array
    {
        return [
            'type' => $document->type->value,
            'custom_name' => $document->custom_name,
            'document_number' => $document->document_number,
            'issue_date' => $document->issue_date?->toDateString(),
            'expiry_date' => $document->expiry_date->toDateString(),
            'notes' => $document->notes,
        ];
    }
}
