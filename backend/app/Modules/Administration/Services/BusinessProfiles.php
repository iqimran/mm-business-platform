<?php

namespace App\Modules\Administration\Services;

use App\Modules\Administration\Actions\Settings\UpdateSetting;
use App\Modules\Administration\Models\Setting;
use App\Modules\Identity\Models\User;

/**
 * Business identity per business line (name, address, contact), printed as the letterhead of that
 * module's documents: payment slips/receipts and report exports (PDF and Excel).
 * Stored as settings ("business_profile.car", "business_profile.restaurant"); saved through the
 * audited UpdateSetting action. Unconfigured profiles fall back to the application name.
 */
class BusinessProfiles
{
    public const MODULES = ['car' => 'Car business', 'restaurant' => 'Restaurant business'];

    private const FIELDS = ['name', 'address', 'phone', 'email'];

    public function __construct(private readonly UpdateSetting $settings) {}

    public static function key(string $module): string
    {
        return "business_profile.{$module}";
    }

    /**
     * @return array{module: string, label: string, name: ?string, address: ?string, phone: ?string, email: ?string, configured: bool}
     */
    public function get(string $module): array
    {
        $value = Setting::where('key', self::key($module))->first()?->value;
        $value = is_array($value) ? $value : [];
        $fields = [];
        foreach (self::FIELDS as $field) {
            $fields[$field] = is_string($value[$field] ?? null) && trim($value[$field]) !== '' ? $value[$field] : null;
        }

        return ['module' => $module, 'label' => self::MODULES[$module]] + $fields + ['configured' => $fields['name'] !== null];
    }

    /**
     * @param  array{name: string, address?: ?string, phone?: ?string, email?: ?string}  $data
     */
    public function save(User $actor, string $module, array $data): array
    {
        $value = [];
        foreach (self::FIELDS as $field) {
            $value[$field] = $data[$field] ?? null;
        }

        $this->settings->handle($actor, self::key($module), $value, self::MODULES[$module].' letterhead (name, address, contact) for printed documents and exports');

        return $this->get($module);
    }

    /**
     * Letterhead for documents: the business name and up to two detail lines (address; phone · email).
     *
     * @return array{name: string, lines: list<string>}
     */
    public function letterhead(string $module): array
    {
        $profile = $this->get($module);
        $contact = collect([
            $profile['phone'] ? 'Phone: '.$profile['phone'] : null,
            $profile['email'] ? 'Email: '.$profile['email'] : null,
        ])->filter()->implode(' · ');

        return [
            'name' => $profile['name'] ?? $this->applicationName(),
            'lines' => array_values(array_filter([$profile['address'], $contact !== '' ? $contact : null])),
        ];
    }

    private function applicationName(): string
    {
        $name = Setting::where('key', 'app.name')->first()?->value;

        return is_string($name) && $name !== '' ? $name : (string) config('app.name');
    }
}
