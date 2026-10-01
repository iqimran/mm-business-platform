<?php

namespace App\Modules\Administration\Http\Requests;

use Closure;
use Illuminate\Foundation\Http\FormRequest;

class UpdateSettingRequest extends FormRequest
{
    private const MAX_VALUE_BYTES = 65535;

    public function authorize(): bool
    {
        return $this->user()->can('setting.update');
    }

    public function rules(): array
    {
        return [
            'value' => ['present', function (string $attribute, mixed $value, Closure $fail) {
                if (strlen((string) json_encode($value)) > self::MAX_VALUE_BYTES) {
                    $fail('The setting value is too large.');
                }
            }],
            'description' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }
}
