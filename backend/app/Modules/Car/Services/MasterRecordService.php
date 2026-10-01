<?php

namespace App\Modules\Car\Services;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Create/update/delete for shared car master data (dealers, parties, expense types):
 * transactional and audited. $entity is the audit entity type, e.g. "car_dealer".
 */
class MasterRecordService
{
    private const NOT_AUDITED = ['id', 'created_at', 'updated_at'];

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @template T of Model
     *
     * @param  class-string<T>  $modelClass
     * @return T
     */
    public function create(User $actor, string $modelClass, string $entity, array $data): Model
    {
        return DB::transaction(function () use ($actor, $modelClass, $entity, $data) {
            $record = $modelClass::create($data);

            $this->audit->record("{$entity}.created", $entity, $record->getKey(), $actor->id,
                newValues: Arr::except($record->getAttributes(), self::NOT_AUDITED));

            return $record;
        });
    }

    public function update(User $actor, Model $record, string $entity, array $data): Model
    {
        return DB::transaction(function () use ($actor, $record, $entity, $data) {
            $record->fill($data);
            $changed = array_keys(Arr::except($record->getDirty(), self::NOT_AUDITED));
            $old = Arr::only($record->getRawOriginal(), $changed);
            $record->save();

            if ($changed !== []) {
                $this->audit->record("{$entity}.updated", $entity, $record->getKey(), $actor->id,
                    oldValues: $old, newValues: Arr::only($record->getAttributes(), $changed));
            }

            return $record;
        });
    }

    /**
     * @param  string|null  $inUseReason  When set, the record is referenced and must be deactivated instead.
     */
    public function delete(User $actor, Model $record, string $entity, ?string $inUseReason = null): void
    {
        if ($inUseReason !== null) {
            throw new ConflictHttpException($inUseReason);
        }

        DB::transaction(function () use ($actor, $record, $entity) {
            $old = Arr::except($record->getAttributes(), self::NOT_AUDITED);
            $record->delete();

            $this->audit->record("{$entity}.deleted", $entity, $record->getKey(), $actor->id, oldValues: $old);
        });
    }
}
