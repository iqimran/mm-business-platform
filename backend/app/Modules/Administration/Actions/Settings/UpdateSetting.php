<?php

namespace App\Modules\Administration\Actions\Settings;

use App\Modules\Administration\Models\Setting;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Identity\Models\User;
use Illuminate\Support\Facades\DB;

class UpdateSetting
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Creates the setting if it does not exist yet.
     */
    public function handle(User $actor, string $key, mixed $value, ?string $description = null): Setting
    {
        return DB::transaction(function () use ($actor, $key, $value, $description) {
            $setting = Setting::where('key', $key)->lockForUpdate()->first() ?? new Setting(['key' => $key]);
            $old = $setting->exists ? ['key' => $key, 'value' => $setting->value] : null;

            $setting->value = $value;
            if ($description !== null) {
                $setting->description = $description;
            }
            $setting->save();

            $this->audit->record('setting.updated', 'setting', $setting->id, $actor->id,
                oldValues: $old, newValues: ['key' => $key, 'value' => $setting->value]);

            return $setting;
        });
    }
}
