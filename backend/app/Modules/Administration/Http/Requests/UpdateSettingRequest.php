<?php

namespace App\Modules\Administration\Http\Requests;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateSettingRequest extends FormRequest
{
    private const MAX_VALUE_BYTES = 65535;

    public function authorize(): bool
    {
        return $this->user()->can('setting.update');
    }

    /** Business profiles have their own validated endpoint (/business-profiles/{module}). */
    public function after(): array
    {
        return [function (Validator $validator) {
            if (str_starts_with((string) $this->route('key'), 'business_profile.')) {
                $validator->errors()->add('key', 'Business profiles are edited in the business profile settings.');
            }
        }];
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
