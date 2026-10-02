<?php

namespace App\Modules\Administration\Services;

use App\Modules\Administration\Models\Setting;

/**
 * The application's display name: the "app.name" setting, falling back to config('app.name').
 */
final class ApplicationName
{
    public const SETTING_KEY = 'app.name';

    public static function get(): string
    {
        $name = Setting::where('key', self::SETTING_KEY)->first()?->value;

        return is_string($name) && trim($name) !== '' ? trim($name) : (string) config('app.name');
    }
}
